FROM debian:12

# Package installed
RUN apt update && \
    apt install -y curl xz-utils zip moreutils openvpn gnupg

# GCSFuse installed
RUN curl https://packages.cloud.google.com/apt/doc/apt-key.gpg | gpg --dearmor -o /usr/share/keyrings/cloud.google.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/cloud.google.gpg] https://packages.cloud.google.com/apt gcsfuse-bookworm main" > /etc/apt/sources.list.d/gcsfuse.list && \
    apt update && \
    apt install -y gcsfuse && \
    rm -rf /var/lib/apt/lists/*

# Deno installed
RUN curl -fsSL https://deno.land/install.sh | sh
RUN export DENO_INSTALL="$HOME/.deno" && export PATH="$DENO_INSTALL/bin:$PATH"

# AWS CLI installed
RUN curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64-2.22.35.zip" -o "awscliv2.zip"
RUN unzip -q awscliv2.zip
RUN ./aws/install

# ffmpeg ffprobe installed
RUN curl -LO https://github.com/yt-dlp/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linux64-gpl.tar.xz
RUN tar Jxfv ffmpeg-master-latest-linux64-gpl.tar.xz
RUN export PATH=$PATH:/ffmpeg-master-latest-linux64-gpl/bin/