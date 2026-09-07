# Sync script: merges production plugin files from development into main
# and strips development-only folders (tests, development-plan, docs, research, bin).

$ErrorActionPreference = "Stop"

Write-Host "=========================================================="
Write-Host "  Syncing 'development' into 'main' (Clean Plugin Release)"
Write-Host "=========================================================="

# Check for uncommitted changes
$status = git status --porcelain
if ($status) {
    Write-Error "Working directory is not clean. Please commit or stash your changes before syncing."
    exit 1
}

# 1. Checkout main
Write-Host "Checking out main branch..."
git checkout main

# 2. Merge development changes without committing
Write-Host "Merging development into main..."
git merge --no-commit --no-ff development 2>$null

# Ensure .gitignore and .gitattributes for main are strictly preserved
if (Test-Path .gitignore) {
    git checkout HEAD -- .gitignore 2>$null
    git add .gitignore 2>$null
}
if (Test-Path .gitattributes) {
    git checkout HEAD -- .gitattributes 2>$null
    git add .gitattributes 2>$null
}

# 3. Remove all development-only directories and files from main
$DevItems = @(
    "tests",
    "development-plan",
    "research",
    "bin",
    "docs",
    "release",
    "gco-stock-sync-build-plan.md"
)

foreach ($item in $DevItems) {
    if (Test-Path $item) {
        git rm -rf --ignore-unmatch $item 2>$null
    }
}

# Also remove any stray markdown files from root (preserve readme.txt)
Get-ChildItem -Path . -Filter "*.md" -File | ForEach-Object {
    git rm -f --ignore-unmatch $_.Name 2>$null
}

# 4. Commit clean release to main if there are changes
$diff = git status --porcelain
if ($diff) {
    Write-Host "Committing clean release to main..."
    git commit -m "chore(release): sync clean plugin from development"
    Write-Host "Pushing main to GitHub..."
    git push origin main
} else {
    Write-Host "No changes to commit. main is already up to date."
}

# 5. Switch back to development
Write-Host "Returning to development branch..."
git checkout development

Write-Host "=========================================================="
Write-Host "Sync Complete! 'main' contains only clean plugin files."
Write-Host "=========================================================="
