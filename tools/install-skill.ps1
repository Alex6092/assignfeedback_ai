<#
  Installe le skill « moodle-ciel » pour Claude Code (~/.claude/skills) et Codex (~/.agents/skills).

  Par défaut, crée des jonctions vers le dossier du dépôt : une mise à jour du dépôt (git pull) met
  le skill à jour partout, sans recopier. Les jonctions de dossier ne demandent pas de droits
  administrateur. Avec -Copy, copie le dossier à la place (à relancer après chaque mise à jour).

  Utilisation :
    powershell -ExecutionPolicy Bypass -File tools\install-skill.ps1
    powershell -ExecutionPolicy Bypass -File tools\install-skill.ps1 -Copy
    powershell -ExecutionPolicy Bypass -File tools\install-skill.ps1 -Uninstall
#>
param(
    [switch]$Copy,
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'
$name = 'moodle-ciel'
$source = Join-Path (Split-Path -Parent $PSScriptRoot) "skills\$name"
if (-not (Test-Path (Join-Path $source 'SKILL.md'))) {
    throw "Skill introuvable : $source"
}

$targets = @(
    (Join-Path $HOME ".claude\skills\$name"),   # Claude Code
    (Join-Path $HOME ".agents\skills\$name")    # Codex
)

foreach ($target in $targets) {
    $parent = Split-Path -Parent $target
    if (Test-Path $target) {
        $item = Get-Item $target -Force
        if ($item.LinkType) {
            # Jonction ou lien : on ne supprime que le lien, jamais la cible.
            $item.Delete()
        } else {
            $backup = "$target.sauvegarde-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
            Move-Item $target $backup
            Write-Host "Ancienne copie déplacée vers $backup"
        }
    }
    if ($Uninstall) {
        Write-Host "Désinstallé : $target"
        continue
    }
    if (-not (Test-Path $parent)) {
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
    }
    if ($Copy) {
        Copy-Item $source $target -Recurse
        Write-Host "Copié : $target"
    } else {
        New-Item -ItemType Junction -Path $target -Target $source | Out-Null
        Write-Host "Jonction : $target -> $source"
    }
}

if (-not $Uninstall) {
    Write-Host ''
    Write-Host 'Ouvrez une nouvelle session Claude Code ou Codex pour que le skill soit chargé.'
}
