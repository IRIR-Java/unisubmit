# Starts the UniSubmit VM at Windows logon. Inside the VM, unisubmit.service starts Docker, and
# the app is published with Tailscale Funnel from inside the VM.
$log = Join-Path $PSScriptRoot 'start-vm.log'
$mp = 'C:\Program Files\Multipass\bin\multipass.exe'
"$(Get-Date -Format s) logon start" | Out-File $log -Append
for ($i = 1; $i -le 20; $i++) {
    & $mp start cosc428-vm *>> $log
    if ((& $mp info cosc428-vm --format csv 2>$null) -match 'Running') { "$(Get-Date -Format s) VM running" | Out-File $log -Append; break }
    Start-Sleep -Seconds 15
}
