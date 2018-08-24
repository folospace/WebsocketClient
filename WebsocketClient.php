<?php

/**
 * Class WebsocketClient
 * $client = new WebsocketClient('127.0.0.1', '3000');
 *
 * $client->send(['msg' => 'hello']);   //websocket
 * $client->emit('msg', 'hello');       //socket.io
 *
 * $response = $client->receive(); 
 */
class WebsocketClient
{
    protected $socket;
    protected $host;
    protected $port;
    protected $connectTime;
    const EXPIRE_SECONDS = 30;


    public function __construct($host, $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function send($data)
    {
        $this->connect();
        return fwrite($this->socket, $this->hybi10Encode(json_encode($data)));
    }

    public function emit($action, $data)
    {
        $this->connect();
        $frame = '42'.json_encode([$action, $data]));
        return fwrite($this->socket, $this->hybi10Encode($frame));
    }

    public function receive()
    {
        $this->connect();
        $frame = fread($this->socket,1000000);
        return $this->hybi10Decode($frame);
    }

    
    /************************************
     *      private methods below     *
     ************************************/


    private function connect()
    {
        if ($this->socket) {
            if ($this->connectTime > time() - self::EXPIRE_SECONDS) {
                return;
            } else {
                $this->close();
            }
        }
        $this->handShake();
    }

    private function handShake()
    {
        $this->socket = fsockopen($this->host, $this->port);

        $key = $this->generateKey();

        fwrite($this->socket, $this->getHeader($key));

        $response = fread($this->socket, 1024);
        preg_match('#Sec-WebSocket-Accept:\s(.*)$#mU', $response, $matches);

        $response = trim($matches[1]);

        $expectedResonse = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        if ($response == $expectedResonse) {
            $this->connectTime = time();
        } else {
            $this->close();
            throw new \Exception('handshake failed');
        }
    }

    private function close()
    {
        @fclose($this->socket);
        $this->socket = null;
    }


    private function getHeader($key)
    {
        $header[] = "GET /socket.io/?EIO=2&transport=websocket HTTP/1.1";
        $header[] = "Host: http://{$this->host}:{$this->port}";
        $header[] = 'Connection: Upgrade';
        $header[] = 'Upgrade: WebSocket';
        $header[] = "Sec-WebSocket-Key: {$key}";
        $header[] = 'Sec-WebSocket-Version:';
        $header[] = 'Origin: *';
        $header[] = "\r\n";
        return implode("\r\n", $header);
    }

    private function generateKey($length = 16)
    {
        $c = 0;
        $tmp = '';
        while ($c++ * 16 < $length) { $tmp .= md5(mt_rand(), true); }
        return base64_encode(substr($tmp, 0, $length));
    }


    private function hybi10Encode($payload, $type = 'text', $masked = true)
    {
        $frameHead = array();
        $payloadLength = strlen($payload);
        switch ($type) {
            case 'text':
                $frameHead[0] = 129;
                break;
            case 'close':
                $frameHead[0] = 136;
                break;
            case 'ping':
                $frameHead[0] = 137;
                break;
            case 'pong':
                $frameHead[0] = 138;
                break;
        }
        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 255 : 127;
            for ($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }
            if ($frameHead[2] > 127) {
                $this->close();
                return false;
            }
        } elseif ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
        }
        foreach (array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }
        $mask = array();
        if ($masked === true) {
            for ($i = 0; $i < 4; $i++) {
                $mask[$i] = chr(rand(0, 255));
            }
            $frameHead = array_merge($frameHead, $mask);
        }
        $frame = implode('', $frameHead);
        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }
        return $frame;
    }


    private function hybi10Decode($bytes)
    {
        $secondByte = sprintf('%08b', ord($bytes[1]));
        $masked = ($secondByte[0]=='1') ? true : false;
        $dataLength = ($masked===true) ? ord($bytes[1]) & 127 : ord($bytes[1]);
        if ($masked===true)
        {
            if ($dataLength===126)
            {
                $mask = substr($bytes, 4, 4);
                $coded_data = substr($bytes, 8);
            }
            elseif ($dataLength===127)
            {
                $mask = substr($bytes, 10, 4);
                $coded_data = substr($bytes, 14);
            }
            else
            {
                $mask = substr($bytes, 2, 4);
                $coded_data = substr($bytes, 6);
            }
            $decodedData = '';
            for ($i = 0; $i<strlen($coded_data); $i++)
                $decodedData .= $coded_data[$i] ^ $mask[$i % 4];
        } else {
            if ($dataLength===126)
                $decodedData = substr($bytes, 4);
            elseif ($dataLength===127)
                $decodedData = substr($bytes, 10);
            else
                $decodedData = substr($bytes, 2);
        }
        return $decodedData;
    }
}
