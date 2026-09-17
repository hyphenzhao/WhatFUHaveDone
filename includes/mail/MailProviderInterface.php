<?php
/**
 * Mail provider abstraction. v1 ships ImapProvider only; Microsoft Graph / EWS
 * can be added later by implementing this interface and switching on
 * mail_accounts.protocol in mail_provider_for().
 */

class MailException extends Exception {}

interface MailProviderInterface {
    /** Open the connection (throws MailException). $account is a mail_accounts row with 'password' decrypted. */
    public function connect(array $account): void;

    /** @return array<int, array{path:string, display_name:string, delimiter:string, kind:string}> */
    public function listFolders(): array;

    /** @return array{uidvalidity:int, uidnext:int, messages:int, unseen:int} */
    public function folderStatus(string $path): array;

    /** UIDs matching an IMAP SEARCH criteria string (e.g. 'ALL', 'UNSEEN', 'UID 100:*'). Ascending. */
    public function searchUids(string $path, string $criteria): array;

    /**
     * Fetch one message fully parsed.
     * @return array{
     *   uid:int, message_id:string, in_reply_to:string, references:string,
     *   from_name:string, from_email:string, to:array, cc:array, subject:string,
     *   date:?string, size:int, flags:array{seen:bool,flagged:bool,answered:bool},
     *   body_text:string, body_html:string,
     *   attachments:array<int, array{part:string, filename:string, mime:string, size:int, content_id:string, inline:bool}>
     * }
     */
    public function fetchMessage(string $path, int $uid): array;

    /** Raw decoded bytes of one attachment part. */
    public function fetchAttachment(string $path, int $uid, string $partId): string;

    /** Set/clear a flag: 'Seen' | 'Flagged' | 'Answered' */
    public function setFlag(string $path, int $uid, string $flag, bool $on): void;

    /** Move a message to another folder (best effort). */
    public function moveTo(string $path, int $uid, string $destPath): void;

    /** Append a raw RFC822 message (e.g. sent mail) to a folder. */
    public function append(string $path, string $rawMime, array $flags = ['Seen']): void;

    public function close(): void;
}
