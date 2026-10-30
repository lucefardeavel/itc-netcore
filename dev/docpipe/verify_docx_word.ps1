<#
  Optional visual check of a built DOCX with Microsoft Word (Windows, Word installed).
  Opens the DOCX read-only, refreshes the TOC and fields, exports <name>.word.pdf next to it, closes without saving,
  then renders the exported pages to PNG (<name>.word-pages\) with the pipeline's Chromium PDF viewer step in WSL.
  The DOCX itself is never modified.

  usage: powershell -File verify_docx_word.ps1 -Docx <path\to\file.docx>
#>
param(
    [Parameter(Mandatory = $true)][string]$Docx,
    [string]$Distro = 'Ubuntu-24.04'
)
$ErrorActionPreference = 'Stop'
$Docx = (Resolve-Path $Docx).Path
$OutPdf = [IO.Path]::ChangeExtension($Docx, '.word.pdf')
$PngDir = [IO.Path]::ChangeExtension($Docx, '.word-pages')

$word = New-Object -ComObject Word.Application
$word.Visible = $false
$word.DisplayAlerts = 0
try {
    $doc = $word.Documents.Open($Docx, $false, $true, $false)
    foreach ($toc in $doc.TablesOfContents) { $toc.Update() }
    [void]$doc.Fields.Update()
    $pages = $doc.ComputeStatistics(2)
    $doc.ExportAsFixedFormat($OutPdf, 17)
    $doc.Close(0)
} finally {
    $word.Quit()
    [void][Runtime.InteropServices.Marshal]::ReleaseComObject($word)
}
Write-Output "word: $pages pages -> $OutPdf"

function To-Wsl([string]$p) {
    $d = $p.Substring(0, 1).ToLower()
    return '/mnt/' + $d + ($p.Substring(2) -replace '\\', '/')
}
$verify = To-Wsl (Join-Path $PSScriptRoot 'lib\verify.js')
wsl -d $Distro -- node $verify --pages (To-Wsl $OutPdf) (To-Wsl $PngDir) $pages
