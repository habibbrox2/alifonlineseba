[CmdletBinding()]
param(
    [ValidateSet('all','unit','functional','web','console')]
    [string]$Suite='all',
    [switch]$SkipBuild,
    [switch]$SkipLint,
    [switch]$KeepServer
)
$ErrorActionPreference='Stop'
$Root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Php=(Get-Command php -ErrorAction Stop).Source
$Npm=(Get-Command npm -ErrorAction Stop).Source
$Codecept=Join-Path $Root 'vendor\bin\codecept.bat'
$EnvFile=Join-Path $Root '.env.test.local'
$ServerProcess=$null
$ServerLog=Join-Path $Root 'runtime\local-test-server.log'
$ExitCode=1
try {
    if(-not(Test-Path $EnvFile)){throw 'Missing .env.test.local. Copy .env.test.example and configure a dedicated *_test database.'}
    if(-not(Test-Path $Codecept)){throw 'Codeception missing. Run composer install first.'}

    # Safety check: never run destructive fixtures against a normal/dev/prod database.
    $dsnLine=Get-Content $EnvFile | Where-Object {$_ -match '^\s*DB_DSN\s*='} | Select-Object -First 1
    if(-not $dsnLine){throw '.env.test.local must define DB_DSN.'}
    $dsn=($dsnLine -split '=',2)[1].Trim().Trim('"').Trim("'")
    if($dsn -notmatch '^mysql:host=(localhost|127\.0\.0\.1);.*dbname=([A-Za-z0-9_]+)'){throw 'DB_DSN must target local MySQL/MariaDB.'}
    $databaseName=$Matches[2]
    if($databaseName -notmatch '(?i)_test$'){throw "Refusing database '$databaseName'. Name must end in _test."}

    $env:APP_ENV='test'
    $env:APP_DEBUG='true'
    Write-Host "Repository: $Root"
    Write-Host "Suite: $Suite | isolated database: $databaseName"

    if(-not $SkipLint){
        Write-Host '[1/4] PHP syntax validation...'
        $files=@()
        foreach($folder in @('src','tests','config','migrations','scripts')){
            $dir=Join-Path $Root $folder
            if(Test-Path $dir){$files+=Get-ChildItem $dir -Recurse -File -Filter '*.php'}
        }
        $failed=@()
        foreach($file in $files){& $Php -l $file.FullName; if($LASTEXITCODE -ne 0){$failed+=$file.FullName}}
        if($failed.Count){throw "PHP syntax errors: $($failed -join ', ')"}
    }

    if(-not $SkipBuild){
        Write-Host '[2/4] Frontend asset build...'
        Push-Location $Root
        try{& $Npm run build; if($LASTEXITCODE -ne 0){throw "npm run build failed: $LASTEXITCODE"}}finally{Pop-Location}
    }

    Write-Host '[3/4] Checking isolated test database...'
    Push-Location $Root
    try{
        $phpCheck='require "vendor/autoload.php"; Dotenv\Dotenv::createImmutable(getcwd(), ".env.test.local")->load(); new PDO($_ENV["DB_DSN"], $_ENV["DB_USERNAME"] ?? "", $_ENV["DB_PASSWORD"] ?? "", [PDO::ATTR_TIMEOUT => 5]); echo "Test DB connection OK\n";'
        & $Php -r $phpCheck
        if($LASTEXITCODE -ne 0){throw 'Test database connection failed.'}
    }finally{Pop-Location}

    if($Suite -eq 'all' -or $Suite -eq 'web'){
        Write-Host 'Starting PHP server at 127.0.0.1:8099...'
        New-Item -ItemType Directory -Path (Split-Path $ServerLog) -Force | Out-Null
        $serverArgs=@('-S','127.0.0.1:8099','-t',(Join-Path $Root 'public'),(Join-Path $Root 'public\index.php'))
        $ServerProcess=Start-Process -FilePath $Php -ArgumentList $serverArgs -WorkingDirectory $Root -PassThru -WindowStyle Hidden -RedirectStandardOutput $ServerLog -RedirectStandardError ($ServerLog + '.err')
        $ready=$false
        for($i=0;$i -lt 30;$i++){
            if($ServerProcess.HasExited){throw "PHP test server exited. Inspect $ServerLog"}
            try{$null=Invoke-WebRequest -Uri 'http://127.0.0.1:8099/' -UseBasicParsing -TimeoutSec 2;$ready=$true;break}
            catch{if($_.Exception.Response){$ready=$true;break};Start-Sleep -Milliseconds 500}
        }
        if(-not $ready){throw "PHP server did not become ready. Inspect $ServerLog"}
    }

    Write-Host "[4/4] Codeception suite: $Suite"
    Push-Location $Root
    try{
        if($Suite -eq 'all'){& $Codecept run --no-interaction}
        else{& $Codecept run $Suite --no-interaction}
        $ExitCode=$LASTEXITCODE
    }finally{Pop-Location}
    if($ExitCode -ne 0){throw "Codeception failed with exit code $ExitCode. Inspect tests/_output and runtime/local-test-server.log."}
    Write-Host 'Codeception completed successfully.' -ForegroundColor Green
}catch{
    Write-Host ("ERROR: " + $_.Exception.Message) -ForegroundColor Red
    $ExitCode=1
}finally{
    if($ServerProcess -and -not $KeepServer){
        Stop-Process -Id $ServerProcess.Id -Force -ErrorAction SilentlyContinue
        Write-Host 'Stopped local PHP test server.'
    }
}
exit $ExitCode
