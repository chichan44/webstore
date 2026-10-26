<?php
function smtp_read($fp): string {
    $data='';
    while(($line=fgets($fp,515))!==false){
        $data.=$line;
        if(isset($line[3]) && $line[3]===' ') break;
    }
    return $data;
}
function smtp_cmd($fp,?string $cmd,string $expect=''): string {
    if($cmd!==null) fwrite($fp,$cmd."\r\n");
    $response=smtp_read($fp);
    if($expect!=='' && !preg_match('/^('.$expect.')/', $response)) throw new RuntimeException('SMTP error: '.trim($response));
    return $response;
}
function smtp_send_mail(string $to,string $subject,string $body,string $fromEmail,string $fromName): bool {
    $host=trim((string)setting('smtp_host',''));
    if($host==='') return false;
    $port=max(1,min(65535,(int)setting('smtp_port','587')));
    $user=(string)setting('smtp_user','');
    $pass=(string)setting('smtp_pass','');
    $remote=($port===465?'ssl://':'').$host.':'.$port;
    $fp=@stream_socket_client($remote,$errno,$errstr,10,STREAM_CLIENT_CONNECT);
    if(!$fp) throw new RuntimeException('SMTP connect failed: '.$errstr);
    stream_set_timeout($fp,10);
    try{
        smtp_cmd($fp,null,'220');
        $siteHost=parse_url((string)setting('site_url',''),PHP_URL_HOST);
        $ehlo=is_string($siteHost)&&$siteHost!==''?$siteHost:(gethostname()?:'localhost');
        smtp_cmd($fp,'EHLO '.$ehlo,'250');
        if($port===587){
            smtp_cmd($fp,'STARTTLS','220');
            if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP TLS gagal diaktifkan.');
            smtp_cmd($fp,'EHLO '.$ehlo,'250');
        }
        if($user!==''){
            smtp_cmd($fp,'AUTH LOGIN','334');
            smtp_cmd($fp,base64_encode($user),'334');
            smtp_cmd($fp,base64_encode($pass),'235');
        }
        smtp_cmd($fp,'MAIL FROM:<'.$fromEmail.'>','250');
        smtp_cmd($fp,'RCPT TO:<'.$to.'>','250|251');
        smtp_cmd($fp,'DATA','354');
        $headers=[
            'From: '.$fromName.' <'.$fromEmail.'>',
            'To: <'.$to.'>',
            'Subject: =?UTF-8?B?'.base64_encode($subject).'?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        $message=implode("\r\n",$headers)."\r\n\r\n".str_replace("\n.","\n..",$body)."\r\n.";
        smtp_cmd($fp,$message,'250');
        smtp_cmd($fp,'QUIT','221');
        return true;
    }finally{
        if(is_resource($fp)) fclose($fp);
    }
}
function send_text_email(string $to,string $subject,string $body): bool {
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Alamat email pembeli tidak valid.');
    $siteName=trim((string)setting('site_name','Digital Store')) ?: 'Digital Store';
    $from=trim((string)setting('smtp_from_email',''));
    if($from==='' || !filter_var($from,FILTER_VALIDATE_EMAIL)){
        $host=parse_url((string)setting('site_url',''),PHP_URL_HOST);
        $from='no-reply@'.($host?:'localhost');
    }
    $fromName=trim((string)setting('smtp_from_name','')) ?: $siteName;
    if(trim((string)setting('smtp_host',''))!=='') return smtp_send_mail($to,$subject,$body,$from,$fromName);
    $headers="From: {$fromName} <{$from}>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to,$subject,$body,$headers);
}
