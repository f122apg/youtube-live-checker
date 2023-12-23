@echo off
setlocal enabledelayedexpansion

echo deploying workflows...

set WF_BASE_PATH=%~dp0..\gcp\workflows\
set WF_SOURCE=batch-workflows.yaml
set WF_SERVICE_ACCOUNT=workflows-user
set WF_SERVICE_ACCOUNT_FULL=workflows-user@%PROJECT_ID%.iam.gserviceaccount.com

call :create_service_account !WF_SERVICE_ACCOUNT!
call :get_cloud_functions_url WF_CF_REC-NOTIFY_URL
call :create_workflows_env_file !WF_CF_REC-NOTIFY_URL!
call gcloud workflows deploy %WORKFLOW_NAME%^
    --location=%WORKFLOW_REGION%^
    --source=%WF_SOURCE%^
    --call-log-level=log-all-calls^
    --service-account=%WF_SERVICE_ACCOUNT_FULL%

exit /b 0

:get_cloud_functions_url
    for /f "usebackq" %%i in (`gcloud functions describe %CF_REC-NOTIFY_FUNCTION_NAME% --region=%CF_REGION% --gen2 --format="get(serviceConfig.uri)"`) do (
        set %1=%%i/main
    )

    exit /b

:create_service_account
    call gcloud iam service-accounts create %WF_SERVICE_ACCOUNT%
    call gcloud projects add-iam-policy-binding %PROJECT_ID% ^
        --member "serviceAccount:%WF_SERVICE_ACCOUNT_FULL%" ^
        --role "roles/workflows.editor"

    exit /b

:create_workflows_env_file
    set WF_TMPL_ENV_PATH=%WF_BASE_PATH%tmpl_%WF_SOURCE%
    set WF_ENV_PATH=%CF_BASE_PATH%%WF_SOURCE%

    if exist !WF_ENV_PATH! (
        del !WF_ENV_PATH!
    )

    for /f "tokens=1 delims=" %%a in (%WF_TMPL_ENV_PATH%) do (
        echo %%a | find "@OVERRIDE_HERE@" > nul

        if not ERRORLEVEL 1 (
            set LINE=%%a
            set LINE=!LINE:@OVERRIDE_HERE@=!%1!
        ) else (
            set LINE=%%a
        )

        echo !LINE!>>!WF_ENV_PATH!
    )

    exit /b