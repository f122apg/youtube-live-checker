#!/bin/bash

# install dependencies
apt update
apt install -y curl xz-utils zip

# install ffmpeg ffprobe
curl -LO https://github.com/yt-dlp/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linux64-gpl.tar.xz
tar Jxfv ffmpeg-master-latest-linux64-gpl.tar.xz
export PATH=$PATH:/ffmpeg-master-latest-linux64-gpl/bin/

# prepare yt-dlp
curl -LO https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp
chmod +x yt-dlp

# live download
mkdir /work
./yt-dlp -P /work --live-from-start --write-all-thumbnails --audio-quality 0 --keep-video -f bestvideo*+bestaudio/best ${CONTENT_ID}

# compress videos
zip ${CONTENT_ID}.zip /work/*

# move to bucket
mv ${CONTENT_ID}.zip ${OUTPUT_PATH}