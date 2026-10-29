# docpipe: ITC NetCore customer document pipeline

Markdown source to two branded outputs:

1. **PDF**: Markdown, then self-contained print HTML, then PDF through Playwright Chromium.
2. **DOCX**: Markdown to Word through python-docx 1.1.2.

Arabic (RTL, primary) and English (LTR) editions share one template.
Nothing outside this folder is read at build time, except the Playwright and python-docx installs under `/srv/itcnc/qa` and, once, the brand source (see [Assets](#assets)).

## Commands (run in WSL Ubuntu-24.04)

From Windows PowerShell:

```powershell
$P = "/mnt/c/Users/user/Desktop/ITC-NetCore-Lab/deliverables/docpipe"
# one document folder -> <outdir>/<name>.pdf, .docx, .html, .pages/, .check.txt/.json
wsl -d Ubuntu-24.04 -- bash $P/build.sh $P/samples/ar-sample $P/out
# every folder under a root that holds meta.json + content.md
wsl -d Ubuntu-24.04 -- bash $P/build_all.sh $P/samples $P/out
# refresh the brand assets from the product tree (sha256-verified; build.sh runs it automatically when missing)
wsl -d Ubuntu-24.04 -- bash $P/prepare_assets.sh
```

Optional, on Windows with Microsoft Word installed: open the DOCX read-only, refresh its TOC, export `<name>.word.pdf`, and render its pages to `<name>.word-pages\`. The DOCX itself is not modified.

```powershell
powershell -ExecutionPolicy Bypass -File .\verify_docx_word.ps1 -Docx .\out\ITC-NetCore-PPPoE-Operations-Guide-AR.docx
```

The exit code of `build.sh` is 0 when the document passed every check, 3 when a check failed (the outputs are still written), and anything else on a build error.
`build_all.sh` reports how many documents failed.

| Environment variable | Effect |
|---|---|
| `DOCPIPE_NO_VERIFY=1` | build only, skip all checks |
| `DOCPIPE_NO_PNG=1` | run the checks without rendering page PNGs (faster) |
| `DOCPIPE_HF=template` | header/footer through Chromium `displayHeaderFooter` templates instead of CSS page-margin boxes (see limitations) |
| `DOCPIPE_PYTHON` | Python with python-docx (default `/srv/itcnc/qa/docvenv/bin/python`) |
| `DOCPIPE_PLAYWRIGHT` | Playwright module (default `/srv/itcnc/qa/node_modules/playwright`) |

## Outputs

For a document named `<name>` in `<outdir>`:

| File | Content |
|---|---|
| `<name>.pdf` | A4 PDF: cover, TOC with page numbers, running header and footer from page 2, PDF bookmarks (outline), tagged PDF |
| `<name>.docx` | Word document: cover, TOC field, Heading 1-3, header and footer with `PAGE`/`NUMPAGES`, embedded brand fonts |
| `<name>.html` | the self-contained print HTML the PDF was made from (fonts, logo and images inlined) |
| `<name>.pages/page-NN.png` | every PDF page rendered by Chromium's PDF viewer (PDFium), for visual review |
| `<name>.check.txt` / `.json` | verification report (see [Verification](#verification)) |
| `_work/<name>/` | intermediate files: AST, page map, rasterised SVGs for DOCX, DOCX check |

## A document folder

```
my-doc/
  meta.json
  content.md
  images/diagram.svg
```

`meta.json` fields:

| Field | Required | Example |
|---|---|---|
| `title` | yes | `دليل تشغيل مشتركي PPPoE` |
| `subtitle` | yes | one sentence |
| `language` | yes | `ar` or `en` |
| `version` | yes | `V1.0` (capital V, brand rule) |
| `date` | yes | `2026-10-29` |
| `owner` | yes | document owner |
| `status` | yes | `Release Candidate — V1.0 RC (a6b02eba / final-prep)` |
| `source_sha` | yes | `a6b02eba` |
| `audience` | yes | intended readers |
| `name` | no | output file base name (`[A-Za-z0-9._-]`); default is the folder name |
| `doc_type` | no | small label above the cover title, for example `Customer document` |
| `toc_depth` | no | heading levels that are numbered and listed in the TOC (default 3) |
| `docx_fonts` | no | `{"latin": "Inter", "arabic": "Noto Sans Arabic", "mono": "Consolas"}` overrides for Word |

## Markdown subset

| Syntax | Result |
|---|---|
| `# H1`, `## H2`, `### H3` | numbered sections (1, 1.1, 1.1.1), listed in the TOC; `####` gives an unnumbered H4 |
| blank-line separated text | paragraph; two trailing spaces or `<br>` give a line break |
| `**bold**`, `*italic*` / `_italic_`, `` `code` `` | inline styles; `_` inside words such as `tbl_customers` is not treated as emphasis |
| `[text](https://…)` | link (external links are clickable in both PDF and DOCX) |
| `- item`, `* item`, `1. item` | lists; indent by 2 or more spaces to nest; each ordered list restarts at 1 |
| GFM pipe table with a `---` separator row | table; `:--`, `:-:` and `--:` align columns; `\|` escapes a pipe inside a cell |
| `![caption](images/x.svg "optional title"){width=60%}` on its own line | figure with "Figure N" caption (title, else alt text) |
| `> text` | quotation |
| `> [!NOTE]` and the other markers below | callout box |
| ```` ``` ```` fenced block | code block (always LTR, monospace) |
| `---` | horizontal rule |
| `<!-- pagebreak -->` | forced page break (PDF and DOCX) |
| other `<!-- … -->` | ignored |

No raw HTML is passed through, except `<br>`.

### Callouts

```markdown
> [!NOTE]
> Body text, lists or code.

> [!WARNING] Optional custom title
> …
```

| Marker | Box | Default title (EN / AR) |
|---|---|---|
| `[!NOTE]` (also `INFO`, `TIP`) | info blue `#245D99` | Note / ملاحظة |
| `[!WARNING]` (also `CAUTION`, `IMPORTANT`) | warning orange `#A35100` | Warning / تحذير |
| `[!FINANCE-WARNING]` (also `FINANCE`) | danger red `#B42318`, coin icon | Finance warning / تحذير مالي |
| `[!SECURITY-WARNING]` (also `SECURITY`) | navy `#0F2648`, shield icon | Security warning / تحذير أمني |

The tones are the semantic tokens of `nuxr-brand.css`. The thick bar sits on the reading start side: left in English, right in Arabic.

### Images

- Paths are relative to the document folder; keep images in `images/`.
- SVG is preferred: it stays vector in the PDF, and for the DOCX it is rasterised with `rsvg-convert` at 300 dpi for the target width.
  PNG and JPEG are used as they are. In the DOCX a bitmap is never enlarged beyond 150 dpi.
- Figures scale to the text column (174 mm) and are never taller than 190 mm.
  The aspect ratio is always kept. `{width=NN%}` makes a figure narrower.
- Text inside SVG files: use `font-family="Inter, 'Noto Sans Arabic', sans-serif"`. The build sets `FONTCONFIG_FILE` so that both Chromium and `rsvg-convert` resolve those names to the brand TTFs.
- A missing image does not stop the build. It is reported by the build, by `check.txt` (`MISSING …`) and by the DOCX check (a red placeholder line).

### Arabic and bidirectional text

- The `<html>` element, the Word section and every paragraph and table are RTL.
  Arabic text uses Noto Sans Arabic; Latin letters and digits use Inter, through the product's own `unicode-range` split.
- Latin runs inside Arabic text are isolated automatically: `<bdi dir="ltr">` in HTML, separate non-RTL runs in Word, LRI/PDI in the running header and footer.
  A run is the stretch from the first to the last Latin letter or digit between two Arabic letters. Inner spaces, `/`, `—` and balanced brackets stay inside it, so `V1.0 RC (a6b02eba / final-prep)` and `25/5 Mbps` keep their order.
  Short runs (18 characters or fewer, such as `4 Mbps` or `200 GB`) never break across lines.
- The product name `ITC NetCore` stays in Latin script and is isolated (`.itc-wordmark`), following `BRAND_IDENTITY.md`.
- Use Western digits. A range written with a dash between two numbers, such as `00:00–08:00`, follows the RTL reading order. Write `من 00:00 إلى 08:00` when the order matters.
- Arrows (`→`, `←`) are not in the brand font subsets; the check reports them as a non-brand font. Use `›` for menu paths: it is mirrored correctly in Arabic.

## Assets

`prepare_assets.sh` copies these items from `/srv/itcnc/src/final/ui/ui/` into `assets/`:

| Source | Copied to | How it is checked |
|---|---|---|
| `images/brand/netcore-mark*.svg`, `netcore-wordmark*.svg` | `assets/brand/` | byte-identical; each sha256 must match `ASSETS.txt` |
| `fonts/brand/*.woff2` and their licences | `assets/fonts/` | byte-identical; each sha256 must match `FONTS.txt` |
| the `@font-face` rules of `styles/nuxr-brand.css` | `assets/fonts/fontfaces.css` | same `unicode-range` split as the product |

The script also derives the files the DOCX needs; `assets/MANIFEST.sha256` records the hashes.

- `assets/brand/png/*.png`: PNG renditions of the same SVG artwork (`rsvg-convert`). The wordmark is 2070 px wide; the marks are 672 px.
- `assets/fonts/ttf/*.ttf`: TrueType copies of Inter (regular and bold) and Noto Sans Arabic (regular and bold). Inter's CFF outlines are converted to quadratic outlines with fontTools `cu2qu`, because Word embeds TrueType outlines only.

Logo usage:

- The full-colour wordmark goes on the white cover.
- The reversed white mark goes on the navy cover band only. This follows the rule "reversed white on dark surfaces, never the full-colour artwork on dark".
- The full-colour mark appears in the running header, at 12 px.
- The logo is never redrawn. The header's 12 px mark is a sizing wrapper (`<image href>`) around the unmodified `netcore-mark.svg`.

The colours are the `--nc-*` and `--nx-*` tokens of `nuxr-brand.css`, copied into `lib/template.css` and `lib/common.py`. The responsibility line "Developed and maintained by ITC" / «تطوير وصيانة ITC» is printed on the cover; no copyright or trademark statement is printed (LIC-1 is open).

## How it works

```
content.md ─ lib/mdparse.py (AST, section numbers) ─┬─ lib/render_html.py ─ <name>.html ─ lib/render_pdf.js ─ <name>.pdf
meta.json                                            └─ lib/render_docx.py ─ <name>.docx
                              lib/verify.js (page PNGs + HTML checks) · lib/check_docx.py (DOCX structure)
```

**PDF page numbers in the TOC (two passes).** `render_pdf.js` prints the document once. It then reads the PDF's named destinations, which Chromium writes for every TOC link target (`lib/pdfinfo.js`), to learn each heading's page. It writes those numbers into the TOC and prints again, repeating until the page map is stable.

**Running header and footer.** These are CSS page-margin boxes (`@page { @top-left … }`, supported since Chromium 131), so that `@page :first` can keep the cover free of them.

- The header shows the mark, `ITC NetCore`, the tagline and the document title.
- The footer shows the version, the status and "Page X of Y" / «صفحة X من Y».
- Page numbers are physical: the cover is page 1, the same in the footer and the TOC.

**DOCX.**

- The first page uses a different (empty) header and footer.
- The TOC is a real `TOC \o "1-3" \h \z \u` field. It is pre-filled with the headings, marked dirty, and `updateFields` is set, so Word asks to refresh it on open.
- Tables have a repeating header row (`tblHeader`) and rows that do not split.
- Arabic documents set `w:bidi` on the section, the styles and the paragraphs, `w:bidiVisual` on tables, and `w:rtl` plus `lang bidi=ar-IQ` on Arabic runs.
- Word reads the left/right values of `w:jc` and of table borders as start/end in RTL, so the renderer maps visual sides accordingly (`jc_visual`).
- Inter and Noto Sans Arabic are embedded as obfuscated `.odttf` parts, so Word shows the brand fonts on machines that do not have them installed. Pass `--no-embed-fonts` to `render_docx.py`, or change `docx_fonts`, to rely on installed fonts instead. Without embedding, Word substitutes a font when Noto Sans Arabic is missing; Segoe UI and Arial both cover Arabic on Windows.

## Verification

`build.sh` runs the checks and writes `<name>.check.txt`. A run FAILs on any of the following:

- **pages**: the page count, from the PDF page tree.
- **fonts**: the fonts actually used per element, from Chrome DevTools `CSS.getPlatformFontsForNode`. Glyphs in a system fallback font count as font substitution. The only system font allowed is DejaVu Sans Mono inside code.
- **images**: every `<img>` must decode. The report gives the natural size and the size shown.
- **overflow**: any block element in the content or TOC whose `scrollWidth > clientWidth`, or that sticks out of the 174 mm text column. A cover taller than A4 also counts.
- **TOC**: every numbered heading must have a PDF page.
- **DOCX** (`check_docx.py`): Heading styles, the TOC field, repeating table header rows, images, the `PAGE` field in the footer, the RTL section, and `w:rtl` on every Arabic run (Arabic documents only). It also lists the fonts referenced and embedded.

Page PNGs come from Chromium's built-in PDF viewer (the full `chromium` channel of the Playwright install), so they show the real PDF rather than the HTML. If that build is missing, a full-page HTML screenshot is written instead. `node lib/verify.js --pages <pdf> <dir> [count]` renders any PDF this way.

## Samples

| Folder | Output |
|---|---|
| `samples/ar-sample/` | `out/ITC-NetCore-PPPoE-Operations-Guide-AR.{pdf,docx}`: Arabic, 6 pages |
| `samples/en-sample/` | `out/ITC-NetCore-PPPoE-Operations-Guide-EN.{pdf,docx}`: English, 6 pages |

Both contain a 6-column table, a nested list, a numbered list, an SVG diagram, all four callout types, inline code, a code block and a forced page break. The Arabic sample mixes Arabic with PPPoE, RADIUS, MikroTik, version strings and numbers. The prices, addresses and secrets in both are illustrative.

## Known limitations

- The Markdown subset is deliberately small. There are no footnotes, definition lists, task lists, nested tables, raw HTML, reference-style links or setext headings. Table cells hold inline content only.
- Internal `#anchor` links are clickable in the PDF; in the DOCX they are plain coloured text.
- The DOCX TOC shows page numbers only after Word refreshes the field. Word asks on open; otherwise right-click the TOC and choose Update Field. Word and Chromium paginate differently, so DOCX page numbers can differ from the PDF.
- With `DOCPIPE_HF=template`, the cover page also gets the header and footer, because Chromium draws the templates on every page.
- Chromium decides page breaks. Headings are kept with the next block, and figures, callouts, code blocks and table rows avoid splitting. A block taller than the remaining space still moves to the next page and leaves white space. Check the PNGs, and use `<!-- pagebreak -->` or reorder content when needed.
- Long unbroken tokens in table cells can overflow. The overflow check catches them; break them in the source, or put them in `code`, where long identifiers get break opportunities after `- / = _ : ,`.
- The brand font files are subsets: Latin plus Arabic, without arrows or other symbol blocks. Characters outside them fall back to system fonts, and the check reports this.
- The pipeline expects `rsvg-convert`, Node with Playwright (Chromium 131 or later, for margin boxes) and python-docx in WSL. `prepare_assets.sh` also needs a system `python3` with fontTools and brotli. No other packages are used.
