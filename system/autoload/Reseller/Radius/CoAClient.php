<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\ErrorReporter;

/**
 * Hardened Disconnect / CoA client (RFC 5176; 06_RADIUS_CONTRACT "CoAClient"; SEC-11). It replaces the
 * legacy `shell_exec("echo ... | radclient ...")`: packets are built in PHP and sent over UDP, so no
 * username, address, NAS name or secret ever reaches a shell or a process argument list.
 *
 *  - only NAS rows from the `nas` table are contacted (exact nasname = session NAS address);
 *  - the CoA port is nas.ports when it is a valid port (legacy compatibility), else 3799;
 *  - the identical request is retransmitted on timeout (RADIUS retransmission, same identifier and
 *    authenticator), then the outcome is UNKNOWN: no new request is ever generated for an unanswered one;
 *  - ACK -> SUCCESS; NAK -> FAILED with the Error-Cause, except Disconnect NAK 503 "Session Context Not
 *    Found" (the session is already gone) -> SUCCESS; responses with a wrong identifier or authenticator are
 *    ignored.
 * The secret is never logged or returned.
 */
final class CoAClient
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly RadiusRepository $repository,
        private readonly float $timeout = 2.0,
        private readonly int $retransmissions = 2,
    ) {
    }

    public static function connection(): self
    {
        $pdo = \ORM::get_db('radius');
        return new self(new AccountingService($pdo), new RadiusRepository($pdo));
    }

    /**
     * Ends every open session of a subscriber (Disconnect-Request per session).
     *
     * @return list<ProvisionResult> one result per open session ([] when the subscriber is offline)
     */
    public function disconnect(string $username): array
    {
        return array_map(fn(array $s): ProvisionResult => $this->toSessionNas($s, RadiusPacket::DISCONNECT_REQUEST, []), $this->accounting->openSessions($username));
    }

    /**
     * WP8 A3: Disconnect-Request for ONE accounting session exactly as captured (a radacct row), to that row's NAS.
     * The row is never re-read by name, so a later session of the same or an equivalent name is never targeted.
     *
     * @param array<string,mixed> $session
     */
    public function disconnectSession(array $session): ProvisionResult
    {
        return $this->toSessionNas($session, RadiusPacket::DISCONNECT_REQUEST, []);
    }

    /**
     * Changes the authorization of every open session (CoA-Request), e.g. a new Mikrotik-Rate-Limit.
     *
     * @param list<array{0:string,1:string|int}> $attributes
     * @return list<ProvisionResult>
     */
    public function changeAuthorization(string $username, array $attributes): array
    {
        return array_map(fn(array $s): ProvisionResult => $this->toSessionNas($s, RadiusPacket::COA_REQUEST, $attributes), $this->accounting->openSessions($username));
    }

    /** @param list<array{0:string,1:string|int}> $extra */
    private function toSessionNas(array $session, int $code, array $extra): ProvisionResult
    {
        $address = (string) ($session['nasipaddress'] ?? '');
        $nas = $address !== '' ? ($this->repository->nasByAddress($address)[0] ?? null) : null;
        if ($nas === null || (string) ($nas['secret'] ?? '') === '') {
            return ProvisionResult::failed('NAS_UNKNOWN', 'The session NAS is not a configured NAS.', false, null, ['session' => (string) $session['acctsessionid']]);
        }
        $attributes = [['User-Name', (string) $session['username']]];
        if ((string) ($session['acctsessionid'] ?? '') !== '') {
            $attributes[] = ['Acct-Session-Id', (string) $session['acctsessionid']];
        }
        if (filter_var((string) ($session['framedipaddress'] ?? ''), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $attributes[] = ['Framed-IP-Address', (string) $session['framedipaddress']];
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $attributes[] = ['NAS-IP-Address', $address];
        }
        return $this->send($nas, $code, [...$attributes, ...$extra]);
    }

    /**
     * @param array<string,mixed> $nas
     * @param list<array{0:string,1:string|int}> $attributes
     */
    public function send(array $nas, int $code, array $attributes): ProvisionResult
    {
        $host = trim((string) $nas['nasname']);
        $nasId = (string) ($nas['id'] ?? $host);
        $port = (int) ($nas['ports'] ?? 0);
        $port = $port >= 1 && $port <= 65535 ? $port : 3799;
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return ProvisionResult::failed('NAS_ADDRESS_INVALID', 'The NAS address is not an IP address.', false, $nasId);
        }
        try {
            $request = RadiusPacket::request($code, random_int(0, 255), $attributes, (string) $nas['secret']);
        } catch (\InvalidArgumentException $e) {
            return ProvisionResult::failed('COA_REQUEST_INVALID', 'The CoA request could not be built.', false, $nasId);
        }
        $target = (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
        $socket = @stream_socket_client('udp://' . $target, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            ErrorReporter::log('radius.coa_socket', 'nas=' . $nasId);
            return ProvisionResult::failed('COA_SEND_FAILED', 'The CoA request could not be sent.', true, $nasId);
        }
        try {
            for ($attempt = 0; $attempt <= $this->retransmissions; $attempt++) {
                if (@fwrite($socket, $request->raw) !== strlen($request->raw)) {
                    if ($attempt === 0) {
                        return ProvisionResult::failed('COA_SEND_FAILED', 'The CoA request could not be sent.', true, $nasId);
                    }
                    break;
                }
                $deadline = microtime(true) + $this->timeout;
                while (($left = $deadline - microtime(true)) > 0) {
                    stream_set_timeout($socket, (int) $left, (int) (($left - (int) $left) * 1000000));
                    $raw = @fread($socket, 4096);
                    if ($raw === false || $raw === '') {
                        break;
                    }
                    try {
                        $response = RadiusPacket::decode($raw);
                    } catch (\InvalidArgumentException) {
                        continue;
                    }
                    if (!RadiusPacket::verifyResponse($response, $request, (string) $nas['secret'])) {
                        continue;
                    }
                    return self::outcome($code, $response, $nasId);
                }
            }
        } finally {
            fclose($socket);
        }
        return ProvisionResult::unknown('COA_NO_RESPONSE', 'The NAS did not answer; the session state is unknown.', $nasId);
    }

    private static function outcome(int $code, RadiusPacket $response, string $nasId): ProvisionResult
    {
        $ack = $code === RadiusPacket::DISCONNECT_REQUEST ? RadiusPacket::DISCONNECT_ACK : RadiusPacket::COA_ACK;
        $nak = $code === RadiusPacket::DISCONNECT_REQUEST ? RadiusPacket::DISCONNECT_NAK : RadiusPacket::COA_NAK;
        if ($response->code === $ack) {
            return ProvisionResult::success('COA_ACK', 'The NAS confirmed the request.', $nasId);
        }
        $cause = $response->get('Error-Cause');
        if ($response->code === $nak) {
            if ($code === RadiusPacket::DISCONNECT_REQUEST && (int) $cause === 503) {
                return ProvisionResult::success('SESSION_NOT_FOUND', 'The session had already ended.', $nasId, ['error_cause' => 503]);
            }
            return ProvisionResult::failed('COA_NAK', 'The NAS refused the request.', false, $nasId, ['error_cause' => is_int($cause) ? $cause : null]);
        }
        return ProvisionResult::unknown('COA_UNEXPECTED_REPLY', 'The NAS sent an unexpected reply.', $nasId, ['reply_code' => $response->code]);
    }
}
