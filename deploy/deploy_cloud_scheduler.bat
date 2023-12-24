@echo off
setlocal enabledelayedexpansion

echo [96mdeploying cloud scheduler...[0m

set CS_JOB_NAME=checkLiveCron
set CS_LOCATION=asia-northeast1
set CS_SCHEDULE="*/10 * * * *"
set CS_TIMEZONE=Asia/Tokyo
set CS_MESSAGE_BODY_FILE=%~dp0..\gcp\cloud_scheduler\message_body.csv

call :deploy_cloud_scheduler

exit /b 0

:deploy_cloud_scheduler
    call gcloud scheduler jobs create pubsub !CS_JOB_NAME!^
        --location=!CS_LOCATION!^
        --schedule=!CS_SCHEDULE!^
        --topic=%PS_TOPIC_NAME%^
        --time-zone=!CS_TIMEZONE!^
        --message-body-from-file=!CS_MESSAGE_BODY_FILE!