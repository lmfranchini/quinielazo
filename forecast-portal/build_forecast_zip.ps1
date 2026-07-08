$tempDir = Join-Path $env:TEMP "forecast_build"
$tempZip = Join-Path $env:TEMP "forecast-portal.zip"
$currentDir = Join-Path (Get-Location) "forecast-portal"
Write-Host "Temp directory is: $tempDir"
Write-Host "Temp zip path is: $tempZip"

if (Test-Path $tempDir) {
    Remove-Item -Path $tempDir -Recurse -Force -ErrorAction SilentlyContinue
}
if (Test-Path $tempZip) {
    Remove-Item -Path $tempZip -Force -ErrorAction SilentlyContinue
}

# Copy contents of current folder to tempDir
New-Item -ItemType Directory -Force -Path $tempDir | Out-Null
Copy-Item -Path "$currentDir\*" -Destination $tempDir -Recurse -Force

# Remove unnecessary files and configuration secrets
Remove-Item -Path (Join-Path $tempDir "sessions") -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item -Path (Join-Path $tempDir "config.php") -Force -ErrorAction SilentlyContinue
Remove-Item -Path (Join-Path $tempDir "build_forecast_zip.ps1") -Force -ErrorAction SilentlyContinue

# Compress inside temp directory
Write-Host "Compressing archive in temp..."
Compress-Archive -Path "$tempDir\*" -DestinationPath $tempZip -Force

# Move the completed zip file back to the workspace
$destZip = Join-Path $currentDir "forecast-portal.zip"
Write-Host "Deploying zip to destination: $destZip"

if (Test-Path $destZip) {
    Remove-Item -Path $destZip -Force -ErrorAction SilentlyContinue
}
Move-Item -Path $tempZip -Destination $destZip -Force

# Clean up temp directory
Remove-Item -Path $tempDir -Recurse -Force -ErrorAction SilentlyContinue

Write-Host "Build complete! forecast-portal.zip created."
