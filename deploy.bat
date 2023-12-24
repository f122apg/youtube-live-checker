@echo off
setlocal enabledelayedexpansion

if not exist %~dp0\gcp\cloud_scheduler\message_body.csv (
    echo Please create gcp\cloud_scheduler\message_body.csv

    exit /b 1
)

rem cloud functions environment
set WORKFLOW_REGION=asia-northeast1
set WORKFLOW_NAME=check_live
set BATCH_REGION=us-west1
set AWS_SNS_REGION=ap-northeast-1

rem configurations setting
set CONFIG_NAME=youtube-live-checker
set COMPUTE_REGION=us-west1
set COMPUTE_ZONE=us-west1-a

rem global variable
set PS_TOPIC_NAME=call_function
set CF_REC-NOTIFY_FUNCTION_NAME=rec-notify
set CF_REGION=us-west1

set /p PROJECT_ID=[96mPlease input projectId:[0m
set /p ACCOUNT_NAME=[96mPlease input account:[0m
set /p AWS_ACCESS_KEY_ID=[96mPlease input aws access key:[0m
set /p AWS_SECRET_ACCESS_KEY=[96mPlease input secret access key:[0m
set /p AWS_SNS_TOPIC=[96mPlease input aws sns topic:[0m

rem deploying services
call :init_project
call :enable_services
call :create_api_key YOUTUBE_API_KEY

call deploy/deploy_bucket.bat
call deploy/deploy_pubsub.bat
call deploy/deploy_cloud_functions.bat
call deploy/deploy_workflows.bat
call deploy/deploy_cloud_scheduler.bat

echo [96mdeploy compleated[0m
pause

exit /b 0

:init_project
    rem initialize call gcloud
    call gcloud config configurations create temporary
    call echo Y|gcloud config configurations delete %CONFIG_NAME%
    call gcloud config configurations create %CONFIG_NAME%
    call gcloud config set project %PROJECT_ID%
    call gcloud config set account %ACCOUNT_NAME%
    call echo Y|gcloud config set compute/region %COMPUTE_REGION%
    call echo Y|gcloud config set compute/zone %COMPUTE_ZONE%
    call echo Y|gcloud config configurations delete temporary

    exit /b

:enable_services
    echo [96menable services...[0m
    rem Artifact Registry API
    rem Artifact Registry API
    rem Cloud Build API
    rem Cloud Functions API
    rem Cloud Run Admin API
    rem Cloud Logging API
    rem Batch API
    rem Cloud Scheduler API
    rem Cloud Pub/Sub API
    rem Cloud Storage
    rem Cloud Storage API
    rem Google Cloud Storage JSON API
    rem Workflow Executions API
    rem Workflows API
    rem YouTube Data API v3
    call gcloud services enable artifactregistry.googleapis.com^
        cloudbuild.googleapis.com^
        cloudfunctions.googleapis.com^
        run.googleapis.com^
        logging.googleapis.com^
        batch.googleapis.com^
        cloudscheduler.googleapis.com^
        pubsub.googleapis.com^
        storage-component.googleapis.com^
        storage.googleapis.com^
        workflowexecutions.googleapis.com^
        workflows.googleapis.com^
        youtube.googleapis.com

    exit /b

:create_api_key
    echo [96mcreate youtube api key...[0m
    set YOUTUBE_API_KEY_DISPLAY_NAME=Youtube Data API Key
    rem create api key
    call gcloud services api-keys create --display-name="%YOUTUBE_API_KEY_DISPLAY_NAME%" --api-target="service=youtube.googleapis.com"

    for /f "usebackq" %%i in (`gcloud services api-keys list --filter="displayName = '%YOUTUBE_API_KEY_DISPLAY_NAME%'" --format="get(name)"`) do (
        set YOUTUBE_API_KEY_RESOUCE_NAME=%%i
    )
    rem get api keyString
    for /f "usebackq" %%i in (`gcloud services api-keys get-key-string %YOUTUBE_API_KEY_RESOUCE_NAME% --format="get(keyString)"`) do (
        set %1=%%i
    )

    exit /b