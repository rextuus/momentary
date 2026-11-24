# Nur zur lokalen Gegenprobe (nicht Teil des Momentary-Images): dvrescue und ffmpeg aus Debian 13 + MediaArea
FROM debian:13.8-slim
RUN apt-get update && apt-get install -y --no-install-recommends wget ca-certificates ffmpeg python3 \
 && wget -q https://mediaarea.net/repo/deb/repo-mediaarea_1.0-26_all.deb \
 && dpkg -i repo-mediaarea_1.0-26_all.deb && rm repo-mediaarea_1.0-26_all.deb \
 && apt-get update && apt-get install -y --no-install-recommends dvrescue \
 && rm -rf /var/lib/apt/lists/*
