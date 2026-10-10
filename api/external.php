<?php
require_once __DIR__ . '/../includes/external_tools.php';
try {
    // No browser Origin is accepted. Native MCP/HTTP clients omit Origin.
    if (isset($_SERVER['HTTP_ORIGIN'])) throw new ExternalApiError('Browser-origin access is not supported',403);
    $parts=get_path_parts(); $method=get_method();
    $db=get_db();
    $auth=external_authenticate($db,(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
    $reader=new ExternalReader($db,$auth['user_id'],$auth['scopes']);
    if (($parts[2]??'')==='mcp' && count($parts)===3) {
        if ($method!=='POST') { header('Allow: POST'); throw new ExternalApiError('No SSE stream or session to close; use POST',405); }
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')) throw new ExternalApiError('Content-Type must be application/json',415);
        $protocol=$_SERVER['HTTP_MCP_PROTOCOL_VERSION']??'';
        if ($protocol!=='' && $protocol!=='2025-06-18') throw new ExternalApiError('Unsupported MCP protocol version',400);
        $req=external_input();
        $reply=external_rpc($reader,$req);
        if ($reply===null) { http_response_code(202); header('Cache-Control: no-store'); exit; }
        external_json($reply);
    }
    if (($parts[2]??'')!=='v1' || count($parts)!==4) throw new ExternalApiError('Use /api/external/v1/overview, search, read or /api/external/mcp',404);
    $action=$parts[3];
    if ($action==='manifest' && $method==='GET') external_json(['version'=>'1','timezone'=>TIMEZONE,'scopes'=>$auth['scopes'],'tools'=>external_tools($reader)]);
    $expected=$action==='read'?'POST':'GET';
    if ($method!==$expected) { header('Allow: '.$expected); throw new ExternalApiError('Method not allowed',405); }
    $args=$method==='POST'?external_input():$_GET;
    external_validate_args($action,$args);
    $data=$reader->execute($action,$args);
    header('ETag: "'.$data['version'].'"');
    if (trim($_SERVER['HTTP_IF_NONE_MATCH']??'','"')===$data['version'] && $method==='GET') { http_response_code(304); header('Cache-Control: private, no-cache'); exit; }
    external_json($data);
} catch (ExternalApiError $e) {
    if ($e->status===401) header('WWW-Authenticate: Bearer realm="WorkLog read-only API"');
    if ($e->status===429) header('Retry-After: 60');
    external_json(['error'=>$e->getMessage()],$e->status);
} catch (Throwable $e) {
    error_log('External API failed: '.get_class($e).' code '.$e->getCode());
    external_json(['error'=>'Read service unavailable; check server logs and migrations'],500);
}
