<?php

namespace App\Enums;

enum FileStatus: string
{
    case Queued = 'queued';
    case Uploaded = 'uploaded';
    case TranscriptionMissing = 'transcription-missing';
    case Transcribed = 'transcribed';
    case Failed = 'failed';
}
