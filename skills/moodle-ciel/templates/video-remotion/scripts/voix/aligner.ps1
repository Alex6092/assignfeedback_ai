# Reconnaissance vocale de Windows (SAPI, français) sur des fichiers WAV 16 kHz mono.
# Chaque ligne du fichier de tâches : wav <TAB> mode (grammaire|dictee) <TAB> phrases.txt <TAB> sortie.tsv
# En mode « grammaire », le moteur ne peut reconnaître que les phrases du script : il sert à dater
# chaque mot. Sortie : lignes R (énoncé : début, durée, confiance, texte) et W (mot : début relatif,
# durée, confiance, mot) ; X = passage rejeté (texte différent du script).
param([string]$Jobs)
$ErrorActionPreference = "Stop"
$src = Get-Content -Raw -Encoding UTF8 (Join-Path $PSScriptRoot "Aligner.cs")
if (-not ("Aligner" -as [type])) {
  Add-Type -TypeDefinition $src -ReferencedAssemblies System.Speech
}
foreach ($line in Get-Content -Encoding UTF8 $Jobs) {
  if ($line.Trim() -eq "") { continue }
  $p = $line -split "`t"
  $phrases = @()
  if ($p[2] -ne "") {
    $phrases = Get-Content -Encoding UTF8 $p[2] | Where-Object { $_.Trim() -ne "" }
  }
  $res = [Aligner]::Run($p[0], $p[1], [string[]]$phrases)
  [System.IO.File]::WriteAllText($p[3], $res, (New-Object System.Text.UTF8Encoding($false)))
}
