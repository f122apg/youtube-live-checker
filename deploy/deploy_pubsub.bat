@echo off
setlocal enabledelayedexpansion

echo deploying pubsub...

call gcloud pubsub topics create %PS_TOPIC_NAME%

exit /b 0