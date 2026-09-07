# Build script for GCO Supplier Stock Sync release package.
# Generates a clean production zip excluding dev tools and tests.

$ErrorActionPreference = "Stop"

$PluginRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$Version = "1.0.0"
$ReleaseDir = Join-Path $PluginRoot "release"
$ZipFile = Join-Path $ReleaseDir "gco-stock-sync-$Version.zip"
$TempDir = Join-Path $ReleaseDir "temp"
$StagingDir = Join-Path $TempDir "gco-stock-sync"

Write-Host "=========================================================="
Write-Host "  Building GCO Supplier Stock Sync Release v$Version"
Write-Host "=========================================================="

# 1. Clean previous builds
if (Test-Path $ReleaseDir) {
    Remove-Item $ReleaseDir -Recurse -Force
}
New-Item -ItemType Directory -Path $StagingDir -Force | Out-Null

# 2. Exclude patterns
$Excludes = @(
    "tests*",
    "development-plan*",
    "research*",
    "bin*",
    ".git*",
    ".claude*",
    "node_modules*",
    "release*",
    "phpunit.xml",
    ".env*",
    "gco-stock-sync-build-plan.md"
)

# 3. Copy plugin files to staging directory
$Items = Get-ChildItem -Path $PluginRoot -Exclude $Excludes

foreach ($Item in $Items) {
    $Dest = Join-Path $StagingDir $Item.Name
    if ($Item.PSIsContainer) {
        Copy-Item -Path $Item.FullName -Destination $Dest -Recurse -Force
    } else {
        Copy-Item -Path $Item.FullName -Destination $Dest -Force
    }
}

# 4. Generate zip archive
Write-Host "Compressing archive to $ZipFile..."
Compress-Archive -Path $StagingDir -DestinationPath $ZipFile -CompressionLevel Optimal

# 5. Clean up temporary staging
Remove-Item $TempDir -Recurse -Force

# 6. Verify zip contents
$ZipInfo = Get-Item $ZipFile
$SizeKB = [math]::Round($ZipInfo.Length / 1024, 2)
Write-Host "----------------------------------------------------------"
Write-Host "Build Complete!"
Write-Host "File: $ZipFile"
Write-Host "Size: $SizeKB KB"
Write-Host "=========================================================="
