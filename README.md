# youtube-live-checker
Never miss out  your favorite Youtuber streaming.

This software checks and records if youtuber streaming.

## Requirement
* [yt-dlp](https://github.com/yt-dlp/yt-dlp)
* PHP 8.1
* PHP extensions: SQLite3
* Youtube Data API API Key
* Windows OS or Linux OS(Not tested)

## Installation
1. Download source zip
2. Extract zip
3. Rename `setting.ini.example` to `setting.ini`
4. Change setting in `setting.ini`
      * yt_dlp_path
        * Please input yt-dlp path. 
      * download_path
        * MP4 file into download_path.
      * database_name
        * Database path. Changing the value is not recommended.
      * api_key
        * Youtube Data API APIKey.

## Usage
Run command: `php main.php channelId`
Use cron or task scheduler.
