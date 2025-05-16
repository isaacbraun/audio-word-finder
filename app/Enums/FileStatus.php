<?php

namespace App\Enums;

enum FileStatus: string
{
    case Processing = 'processing';
    case TranscriptionMissing = 'transcription-missing';
    case Transcribed = 'transcribed';
    case Failed = 'failed';
}
