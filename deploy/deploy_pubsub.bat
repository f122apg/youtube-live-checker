@echo off
setlocal enabledelayedexpansion

echo [96mdeploying pubsub...[0m

call gcloud pubsub topics create %PS_TOPIC_NAME%

exit /b 0