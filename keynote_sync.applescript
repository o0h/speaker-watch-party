-- keynote_sync.applescript
-- Keynoteのスライド番号をspeaker-watch-partyに送信し続けるスクリプト
-- 起動: osascript keynote_sync.applescript

property API_BASE : "http://localhost"
property POLL_INTERVAL : 0.1 -- 秒

on readEnv(key)
	set scriptDir to do shell script "dirname " & quoted form of POSIX path of (path to me)
	return do shell script "grep '^" & key & "=' " & quoted form of (scriptDir & "/.env") & " | cut -d= -f2"
end readEnv

on log_msg(msg)
	set t to do shell script "date '+%H:%M:%S'"
	log t & "  " & msg
end log_msg

on postSlide(slideNumber, token)
	set cmd to "curl -sk -X POST " & API_BASE & "/api/slide/" & slideNumber & space & "-H 'Authorization: Bearer " & token & "'" & space & "-w '\\n%{http_code}'" & space & "-o /tmp/swp_response.txt 2>&1; echo $(cat /tmp/swp_response.txt)"
	set result to do shell script cmd
	my log_msg("POST /api/slide/" & slideNumber & " → " & result)
end postSlide

on postStop(token)
	do shell script "curl -sk -X POST " & API_BASE & "/api/stop -H 'Authorization: Bearer " & token & "' -o /dev/null"
	my log_msg("POST /api/stop")
end postStop

-- メインループ
set API_TOKEN to my readEnv("API_TOKEN")
set envDomain to my readEnv("DOMAIN")
if envDomain is not "" then
	set API_BASE to "https://" & envDomain
end if
my log_msg("起動しました (API: " & API_BASE & ")")
my log_msg("Keynoteのスライドショー開始を待機中...")

set lastSlide to 0

repeat
	try
		tell application "Keynote"
			if it is running and (count of documents) > 0 then
				if playing then
					set currentSlide to 0
					try
						set currentSlide to slide number of current slide of front document
					end try
					if currentSlide is not 0 and currentSlide is not lastSlide then
						my log_msg("スライド変更: " & lastSlide & " → " & currentSlide)
						my postSlide(currentSlide, API_TOKEN)
						set lastSlide to currentSlide
					end if
				else
					if lastSlide is not 0 then
						my log_msg("スライドショー終了を検出")
						my postStop(API_TOKEN)
						set lastSlide to 0
					end if
				end if
			else
				if lastSlide is not 0 then
					my log_msg("Keynoteが閉じられました")
					my postStop(API_TOKEN)
					set lastSlide to 0
				end if
			end if
		end tell
	on error errMsg
		my log_msg("エラー: " & errMsg)
	end try

	delay POLL_INTERVAL
end repeat
