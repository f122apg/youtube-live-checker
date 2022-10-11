#!/bin/bash

# install dependencies
apt update
apt install -y curl xz-utils

# install ffmpeg ffprobe
curl -LO https://github.com/yt-dlp/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linux64-gpl.tar.xz
tar Jxfv ffmpeg-master-latest-linux64-gpl.tar.xz
export PATH=$PATH:/ffmpeg-master-latest-linux64-gpl/bin/

# prepare yt-dlp
curl -LO https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp
chmod +x yt-dlp

# live download
mkdir /work
./yt-dlp -P /work --live-from-start ${CONTENT_ID}

# move to bucket
mv /work/* ${OUTPUT_PATH}