param(
    [Parameter(Mandatory = $true)][string]$In,
    [Parameter(Mandatory = $true)][string]$Out
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $In)) {
    Write-Output 'IN_NOT_FOUND'
    exit 3
}

# Try Microsoft Word first, then WPS (KWPS / WPS ProgIDs)
$progIds = @('Word.Application', 'KWPS.Application', 'WPS.Application', 'kwps.Application')
$app = $null
$used = ''
foreach ($p in $progIds) {
    try {
        $app = New-Object -ComObject $p -ErrorAction Stop
        $used = $p
        break
    } catch {
        $app = $null
    }
}

if ($null -eq $app) {
    Write-Output 'NO_COM'
    exit 2
}

$doc = $null
try {
    try { $app.Visible = $false } catch {}
    try { $app.DisplayAlerts = 0 } catch {}

    # Open(FileName, ConfirmConversions, ReadOnly)
    $doc = $app.Documents.Open($In, $false, $true)

    $saved = $false
    # 17 = wdFormatPDF, works in Word and WPS
    try {
        $doc.SaveAs($Out, 17)
        $saved = $true
    } catch {
        try {
            $doc.SaveAs2($Out, 17)
            $saved = $true
        } catch {
            try {
                $doc.ExportAsFixedFormat($Out, 17)
                $saved = $true
            } catch {
                $saved = $false
            }
        }
    }

    try { $doc.Close(0) } catch {}
    $doc = $null

    if ($saved -and (Test-Path -LiteralPath $Out)) {
        Write-Output ('OK ' + $used)
        exit 0
    } else {
        Write-Output ('SAVE_FAILED ' + $used)
        exit 4
    }
} catch {
    Write-Output ('ERROR ' + $_.Exception.Message)
    exit 5
} finally {
    if ($null -ne $doc) { try { $doc.Close(0) } catch {} }
    if ($null -ne $app) { try { $app.Quit() } catch {} }
}
