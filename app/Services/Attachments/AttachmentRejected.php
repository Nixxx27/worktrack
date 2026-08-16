<?php

namespace App\Services\Attachments;

/**
 * An upload or download that was refused for a reason the user can act on.
 *
 * The message is written to be SHOWN — "that file is 40.2 MB, the limit is 25 MB",
 * not "validation failed". FR-6.8 asks for a clear error, and an upload that fails
 * with a generic message is one the user retries identically until they give up and
 * email the file instead, which is the out-of-band behaviour attachments exist to
 * eliminate.
 *
 * It deliberately says nothing about storage internals — no bucket name, no object
 * key, no driver exception text. Those go to the log.
 */
class AttachmentRejected extends \RuntimeException {}
