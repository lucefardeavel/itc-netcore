| case | interim s | pass s | rate / direction | quota | cut by | t_cross s | t_stop s | latency s | model bound s | used | overshoot | overshoot % of quota |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| matrix fault: CoA closed 40 s | 15 | 5 | 900 kbit/s | 12.0 MB | enforcer (Disconnect CONFIRMED) | 53.6 | 92 | 38.4 | 26 | 20.61 MB | 8.61 MB | 72 % |
| matrix | 60 | 5 | 400 kbit/s | 4.0 MB | enforcer (Disconnect CONFIRMED) | 40.2 | 61 | 20.8 | 71 | 6.07 MB | 2.07 MB | 52 % |
| matrix | 60 | 5 | 900 kbit/s | 9.0 MB | enforcer (Disconnect CONFIRMED) | 40.7 | 62 | 21.3 | 71 | 13.70 MB | 4.70 MB | 52 % |
| matrix | 30 | 5 | 400 kbit/s | 4.0 MB | enforcer (Disconnect CONFIRMED) | 41.3 | 63 | 21.7 | 41 | 6.10 MB | 2.10 MB | 52 % |
| matrix | 30 | 5 | 900 kbit/s | 9.0 MB | enforcer (Disconnect CONFIRMED) | 40.8 | 62 | 21.2 | 41 | 13.67 MB | 4.67 MB | 52 % |
| matrix | 15 | 5 | 400 kbit/s | 4.0 MB | enforcer (Disconnect CONFIRMED) | 40.6 | 46 | 5.4 | 26 | 4.53 MB | 0.53 MB | 13 % |
| matrix | 15 | 5 | 900 kbit/s | 9.0 MB | enforcer (Disconnect CONFIRMED) | 40.7 | 46 | 5.3 | 26 | 10.17 MB | 1.17 MB | 13 % |
| matrix | 10 | 5 | 400 kbit/s | 4.0 MB | enforcer (Disconnect CONFIRMED) | 40.4 | 51 | 10.6 | 21 | 5.05 MB | 1.05 MB | 26 % |
| matrix | 10 | 5 | 900 kbit/s | 9.0 MB | enforcer (Disconnect CONFIRMED) | 41.6 | 53 | 11.4 | 21 | 11.46 MB | 2.46 MB | 27 % |
| matrix | 30 | 15 | 900 kbit/s | 9.0 MB | enforcer (Disconnect CONFIRMED) | 40.2 | 61 | 20.8 | 51 | 13.65 MB | 4.65 MB | 52 % |
| matrix-early-cross | 60 | 5 | 900 kbit/s | 6.0 MB | NAS native per-direction cap | 27 | 54 | 27 | 71 | 12.02 MB | 6.02 MB | 100 % |
