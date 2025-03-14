import sys
import whisper
import json

def find_and_segment(text, search_string):
    result = {
        "matchCount": 0,
        "segments": []
    }

    if not search_string or not text:
        result["segments"].append({"match": False, "text": text})
        return result

    current_position = 0
    search_len = len(search_string)

    while current_position <= len(text):
        # Find the next occurrence of the search string
        next_match_position = text.lower().find(search_string, current_position)

        # If no more matches are found
        if next_match_position == -1:
            # Add the remaining text as a non-matching segment
            if current_position < len(text):
                result["segments"].append({
                    "match": False,
                    "text": text[current_position:]
                })
            break

        # Add the text before the match as a non-matching segment
        if next_match_position > current_position:
            result["segments"].append({
                "match": False,
                "text": text[current_position:next_match_position]
            })

        # Add the matching segment
        result["segments"].append({
            "match": True,
            "text": text[next_match_position:next_match_position + search_len]
        })

        # Increment the match count
        result["matchCount"] += 1

        # Move the current position past this match
        current_position = next_match_position + search_len

    return result

# Take in argument of file path
audio_path = sys.argv[1]
query = sys.argv[2]

model = whisper.load_model("tiny.en")
transcription = model.transcribe(audio_path)

lower_query = query.lower()

result = find_and_segment(transcription["text"], lower_query)

# Output the JSON directly to stdout without any extra formatting
sys.stdout.write(json.dumps(result))
# Flush to ensure all output is sent immediately
sys.stdout.flush()
