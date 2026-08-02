FROM debian:12

ENV DEBIAN_FRONTEND=noninteractive
ENV DENO_INSTALL=/opt/deno
ENV PATH=/opt/deno/bin:/opt/ffmpeg/bin:$PATH

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        iproute2 \
        iptables \
        jq \
        openvpn \
        procps \
        python3 \
        unzip \
        xz-utils \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://deno.land/install.sh | sh \
    && /opt/deno/bin/deno --version

RUN curl -fsSL -o /tmp/ffmpeg.tar.xz \
        https://github.com/yt-dlp/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linux64-gpl.tar.xz \
    && mkdir -p /opt/ffmpeg \
    && tar -xJf /tmp/ffmpeg.tar.xz -C /opt/ffmpeg --strip-components=1 \
    && rm /tmp/ffmpeg.tar.xz \
    && ffmpeg -version | head -n 1

WORKDIR /work
