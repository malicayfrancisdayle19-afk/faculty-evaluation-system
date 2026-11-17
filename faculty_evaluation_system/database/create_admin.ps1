$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$setupScript = Join-Path $PSScriptRoot 'create_admin.php'
$xamppPhp = 'C:\xampp\php\php.exe'
$setLocalEnvironment = [string]::IsNullOrWhiteSpace($env:APP_ENV)

if ($setLocalEnvironment) {
    $env:APP_ENV = 'local'
}

if (Test-Path -LiteralPath $xamppPhp) {
    $phpExecutable = $xamppPhp
} else {
    $phpCommand = Get-Command php -ErrorAction Stop
    $phpExecutable = $phpCommand.Source
}

$name = Read-Host 'Administrator full name'
$email = Read-Host 'Administrator email'
$password = Read-Host 'Administrator password (12+ characters)' -AsSecureString
$confirmation = Read-Host 'Confirm administrator password' -AsSecureString
$passwordPointer = [IntPtr]::Zero
$confirmationPointer = [IntPtr]::Zero

try {
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($password)
    $confirmationPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($confirmation)
    $passwordText = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    $confirmationText = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($confirmationPointer)

    if ($passwordText -cne $confirmationText) {
        throw 'The passwords do not match.'
    }

    $env:ADMIN_SETUP_NAME = $name
    $env:ADMIN_SETUP_EMAIL = $email
    $env:ADMIN_SETUP_PASSWORD = $passwordText
    Push-Location $projectRoot
    try {
        & $phpExecutable $setupScript
        $setupExitCode = $LASTEXITCODE
    } finally {
        Pop-Location
    }
} finally {
    Remove-Item Env:ADMIN_SETUP_NAME -ErrorAction SilentlyContinue
    Remove-Item Env:ADMIN_SETUP_EMAIL -ErrorAction SilentlyContinue
    Remove-Item Env:ADMIN_SETUP_PASSWORD -ErrorAction SilentlyContinue
    if ($setLocalEnvironment) {
        Remove-Item Env:APP_ENV -ErrorAction SilentlyContinue
    }
    if ($passwordPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    }
    if ($confirmationPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($confirmationPointer)
    }
    $passwordText = $null
    $confirmationText = $null
    $password = $null
    $confirmation = $null
}

if ($setupExitCode -ne 0) {
    exit $setupExitCode
}
