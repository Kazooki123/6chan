# If you're using Windows, and have the `anubis.exe` executable setup, feel free to use this,
# i made this after getting frustrated of how fucking ridiculous Podman gets when configuring
# reverse proxy. 

$env:BIND              = ":8923"
$env:TARGET             = "http://localhost:8080"
$env:POLICY_FNAME       = "$PSScriptRoot\botPolicy-site-wide.yaml"
$env:USE_REMOTE_ADDRESS = "true"
$env:DIFFICULTY         = "4"
$env:SERVE_ROBOTS_TXT   = "true"

Write-Host "Starting Anubis..."
Write-Host "  Target (real app):  $env:TARGET"
Write-Host "  Anubis listening:   http://localhost$env:BIND"
Write-Host "  Policy file:        $env:POLICY_FNAME"
Write-Host ""
Write-Host "If a Windows Firewall prompt appears, click Allow - otherwise"
Write-Host "the port never actually opens and youll get connection refused"
Write-Host ""

anubis.exe
