@echo off
setlocal enabledelayedexpansion

echo [96mdeploying cloud functions...[0m

set CF_BASE_PATH=%~dp0..\gcp\cloud_functions\

set CF_CRAWLCHANNEL_FUNCTION_NAME=crawlchannel
set CF_CRAWLCHANNEL_SOURCE=%CF_BASE_PATH%crawlchannel\
set CF_CRAWLCHANNEL_ENV_FILE=crawlchannel.yaml

set CF_REC-NOTIFY_SOURCE=%CF_BASE_PATH%rec-notify/
set CF_REC-NOTIFY_ENV_FILE=rec-notify.yaml

set CF_MEMORY=128
set CF_RUNTIME=php81
set CF_ENTRYPOINT=main

call :create_cf_env_files
call :deploy_cf

exit /b 0

:create_cf_env_files
    echo [96mcreate env file[0m
    set CF_ENV_FILE_1=!CF_CRAWLCHANNEL_ENV_FILE!
    set CF_ENV_FILE_2=!CF_REC-NOTIFY_ENV_FILE!

    for /f "usebackq delims== tokens=1,2" %%a in (`set CF_ENV_FILE`) do (
        set CF_TMPL_ENV_PATH=%CF_BASE_PATH%tmpl_%%b
        set CF_ENV_PATH=%CF_BASE_PATH%%%b

        if exist !CF_ENV_PATH! (
            del !CF_ENV_PATH!
        )

        for /f "tokens=1,* delims=: " %%a in (!CF_TMPL_ENV_PATH!) do (
            set key=%%a
            set value=!%%a!

            echo !key!: !value!>>!CF_ENV_PATH!
        )
    )

    exit /b

:deploy_cf
    echo [96mdeploy functions: %CF_CRAWLCHANNEL_FUNCTION_NAME%[0m

    rem deploy crawlchannel
    call gcloud functions deploy %CF_CRAWLCHANNEL_FUNCTION_NAME%^
        --region=%CF_REGION%^
        --runtime=%CF_RUNTIME%^
        --source=%CF_CRAWLCHANNEL_SOURCE%^
        --entry-point=%CF_ENTRYPOINT%^
        --memory=%CF_MEMORY%MB^
        --env-vars-file=%CF_BASE_PATH%%CF_CRAWLCHANNEL_ENV_FILE%^
        --max-instances=3^
        --docker-registry=artifact-registry^
        --trigger-topic %PS_TOPIC_NAME%

    echo [96mdeploy functions: %CF_REC-NOTIFY_FUNCTION_NAME%[0m

    rem deploy rec-notify
    call gcloud functions deploy %CF_REC-NOTIFY_FUNCTION_NAME%^
        --gen2^
        --region=%CF_REGION%^
        --runtime=%CF_RUNTIME%^
        --source=%CF_REC-NOTIFY_SOURCE%^
        --entry-point=%CF_ENTRYPOINT%^
        --memory=%CF_MEMORY%Mi^
        --env-vars-file=%CF_BASE_PATH%%CF_REC-NOTIFY_ENV_FILE%^
        --no-allow-unauthenticated^
        --trigger-http

    exit /b
