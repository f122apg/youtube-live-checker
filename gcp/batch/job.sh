#!/bin/bash

# install dependencies
apt update
apt install -y curl

# prepare yt-dlp
curl -LO https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp
chmod +x yt-dlp

# live download
mkdir /work
./yt-dlp -P /work --live-from-start ${CONTENT_ID}

# move to bucket
mv /work/* ${OUTPUT_PATH}