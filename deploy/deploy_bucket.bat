@echo off
setlocal enabledelayedexpansion

echo [96mdeploying bucket...[0m

set BUCKET_LOCATION=US-WEST1

call gcloud storage buckets create gs://!PROJECT_ID! --location=!BUCKET_LOCATION! --uniform-bucket-level-access --public-access-prevention
call gcloud storage cp %~dp0..\gcp\bucket\job.sh gs://!PROJECT_ID!

exit /b 0