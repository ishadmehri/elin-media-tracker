<#
  Builds dist/elin-media-tracker-<version>.zip from the committed HEAD (git archive, so .gitattributes decides
  what stays out: tests, scripts) and, with -Publish, tags the version and creates the GitHub release.

    pwsh scripts/release.ps1             # zip only
    pwsh scripts/release.ps1 -Publish    # zip + tag + push + GitHub release
    pwsh scripts/release.ps1 -Publish -Notes "what changed"

  The version comes from the plugin header and must match MTRK_VERSION. Refuses a dirty tree or an existing tag.
#>
param([switch]$Publish, [string]$Notes = '')

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$slug = 'elin-media-tracker'

function Fail($msg) { Write-Error $msg; exit 1 }
function Invoke-Git { & git @args; if ($LASTEXITCODE) { Fail "git $($args -join ' ') failed" } }

$main = Get-Content "$slug.php" -Raw
if ($main -notmatch '(?m)^\s*\*\s*Version:\s*(\S+)') { Fail 'No Version header in the main file.' }
$version = $Matches[1]
if ($main -notmatch "MTRK_VERSION',\s*'$([regex]::Escape($version))'") { Fail "MTRK_VERSION does not match header version $version." }
if (git status --porcelain) { Fail 'Uncommitted changes: commit first, the zip is built from HEAD.' }
if (git tag -l "v$version") { Fail "Tag v$version already exists." }

New-Item -ItemType Directory -Force dist | Out-Null
$zip = Join-Path (Resolve-Path dist) "$slug-$version.zip"
Invoke-Git archive --format=zip --prefix="$slug/" -o $zip HEAD
"Built $zip ($([math]::Round((Get-Item $zip).Length / 1KB)) KB)"

if (-not $Publish) { return }

if (-not $Notes) { $Notes = "Elin Media Tracker $version" }
Invoke-Git tag -a "v$version" -m "v$version"
Invoke-Git push origin "v$version"
& gh release create "v$version" $zip --title "v$version" --notes $Notes
if ($LASTEXITCODE) { Fail 'gh release create failed (the tag is already pushed).' }
