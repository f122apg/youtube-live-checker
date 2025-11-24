#!/bin/bash

source /mnt/share/vpngate_lib.sh

mkdir -p ~/.config/yt-dlp
cat <<EOF > ~/.config/yt-dlp/config
# EJS Setup - Enable Deno runtime
--js-runtimes deno

# Remote components - Download EJS scripts from GitHub
--remote-components ejs:github

# Change language
--add-header 'Accept-Language:ja-JP'
--extractor-args "youtube:lang=ja;youtube:player-client=default,-ios"
--parse-metadata " Japanese: %(meta_language)s"

# Download base directory
--paths /work

# Save file path
--output '%(id)s.%(ext)s'

# With thumbnail image
--write-all-thumbnails
--output 'thumbnail:%(id)s_thumbnail.%(ext)s'

# Write metadata
--write-info-json

# Live download
--live-from-start

# Audio Format
--audio-quality 0

# Video Format
--format 'bestvideo*+bestaudio/best'
EOF

# install dependencies
apt update
apt install -y curl xz-utils zip moreutils openvpn
#apt install -y git curl xz-utils zip moreutils python3-pip

# Install Deno (Recommended JS runtime for EJS)
echo "Installing Deno..."

curl -fsSL https://deno.land/install.sh | sh
export DENO_INSTALL="$HOME/.deno"
export PATH="$DENO_INSTALL/bin:$PATH"

# Verify Deno installation
deno --version

# # install nodejs
# curl -fsSL https://deb.nodesource.com/setup_23.x -o nodesource_setup.sh
# bash nodesource_setup.sh
# apt install -y nodejs
# node -v

# # install yarn
# npm install -g yarn

# install ffmpeg ffprobe
curl -LO https://github.com/yt-dlp/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linux64-gpl.tar.xz
tar Jxfv ffmpeg-master-latest-linux64-gpl.tar.xz
export PATH=$PATH:/ffmpeg-master-latest-linux64-gpl/bin/

# prepare aws cli
curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64-2.22.35.zip" -o "awscliv2.zip"
unzip -q awscliv2.zip
sudo ./aws/install

# prepare yt-dlp
curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux -o "yt-dlp"
chmod +x yt-dlp

# # install po token generate tool
# cd ~
# git clone --single-branch --branch 0.7.4 https://github.com/Brainicism/bgutil-ytdlp-pot-provider.git
# cd bgutil-ytdlp-pot-provider/server/
# yarn install --frozen-lockfile
# npx tsc


# python3 -m pip install -U bgutil-ytdlp-pot-provider

# cd /

# check container
docker ps -a

# live download
mkdir /work

sleep 60

vpn_connect

./yt-dlp -v -4 ${CONTENT_ID}
YTDLP_EXIT_CODE=$?

if [ ${YTDLP_EXIT_CODE} -ne 0 ]; then
    exit ${YTDLP_EXIT_CODE}
fi

jq 'del(.formats, .automatic_captions)' /work/${CONTENT_ID}.info.json | sponge /work/${CONTENT_ID}.info.json

cat <<EOF > ~/.config/yt-dlp/config
# EJS Setup - Enable Deno runtime
--js-runtimes deno

# Remote components - Download EJS scripts from GitHub
--remote-components ejs:github

# Change language
--add-header 'Accept-Language:ja-JP'
--extractor-args "youtube:lang=ja"
--parse-metadata " Japanese: %(meta_language)s"

# Download base directory
--paths /work
EOF

# download channel info
avatar_url='null'
avatar_image_ext='null'
channel_banner_url='null'
channel_banner_image_ext='null'

channel_url=`./yt-dlp --skip-download --exec "pre_process:python3 -c 'import urllib.parse; import sys; print(\"https://\" + urllib.parse.quote(sys.argv[1].replace(\"https://\", \"\")))' %(uploader_url)q" -q ${CONTENT_ID}`
if [ $? -eq 0 ] && [[ "$channel_url" == *"https://"* ]]; then
    avatar_url=`./yt-dlp --skip-download --exec "pre_process:python3 -c 'import urllib.parse; import sys; print(\"https://\" + urllib.parse.quote(sys.argv[1].replace(\"https://\", \"\")))' %(uploader_url)q | xargs ../yt-dlp -I0 -O \"playlist:%%(thumbnails.-1.url)s\"" -q ${CONTENT_ID}`
    if [ $? -eq 0 ] && [[ "$avatar_url" == *"https://"* ]]; then
        echo "Found avatar url: $avatar_url"
        echo 'Downloading avatar image.'

        avatar_image_ext=$(curl -sI $avatar_url | grep -i "content-type:" | awk '{print $2}' | tr -d '\r' | sed 's#image/##g')
        curl -L $avatar_url -o /work/avatar.$avatar_image_ext
    else
        echo 'Not found avatar url.'
        avatar_url='null'
    fi

    channel_banner_url=`./yt-dlp --skip-download --exec "pre_process:python3 -c 'import urllib.parse; import sys; print(\"https://\" + urllib.parse.quote(sys.argv[1].replace(\"https://\", \"\")))' %(uploader_url)q | xargs ../yt-dlp -I0 -O \"playlist:%%(thumbnails.-3.url)s\"" -q ${CONTENT_ID}`
    if [ $? -eq 0 ] && [[ "$channel_banner_url" == *"https://"* ]]; then
        echo "Found channel banner url: $channel_banner_url"
        echo 'Downloading channel banner.'

        channel_banner_image_ext=$(curl -sI $channel_banner_url | grep -i "content-type:" | awk '{print $2}' | tr -d '\r' | sed 's#image/##g')
        curl -L $channel_banner_url -o /work/banner.$channel_banner_image_ext
    else
        echo 'Not found channel banner url.'
        channel_banner_url='null'
    fi
fi

vpn_disconnect

echo 'Writing info.json...'
jq --arg avatar_url $avatar_url '. + {"avatar_url": $avatar_url}' /work/${CONTENT_ID}.info.json | sponge /work/${CONTENT_ID}.info.json
jq --arg avatar_image_ext $avatar_image_ext '. + {"avatar_image_ext": $avatar_image_ext}' /work/${CONTENT_ID}.info.json | sponge /work/${CONTENT_ID}.info.json
jq --arg channel_banner_url $channel_banner_url '. + {"channel_banner_url": $channel_banner_url}' /work/${CONTENT_ID}.info.json | sponge /work/${CONTENT_ID}.info.json
jq --arg channel_banner_image_ext $channel_banner_image_ext '. + {"channel_banner_image_ext": $channel_banner_image_ext}' /work/${CONTENT_ID}.info.json | sponge /work/${CONTENT_ID}.info.json

echo 'Uploading s3...'
# make directory
aws s3api put-object --endpoint-url ${WASABI_S3_URL} --bucket ${WASABI_BUCKET_NAME} --key ${CONTENT_ID}/

# upload to s3
aws s3 cp --endpoint-url ${WASABI_S3_URL} /work s3://${WASABI_BUCKET_NAME}/${CONTENT_ID}/ --recursive

# if failed s3 upload then upload to gcs
if [ $? -ne 0 ]; then
    # compress videos
    zip ${CONTENT_ID}.zip /work/*

    # move to bucket
    mv ${CONTENT_ID}.zip ${OUTPUT_PATH}
fi