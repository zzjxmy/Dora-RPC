<?php
namespace DWDRPC;

include_once __DIR__ .'/RpcConst.php';

class Packet
{

    public static function packFormat($guid, $msg = "OK", $code = 0, $data = null)
    {
        $pack = array(
            "guid" => $guid,
            "code" => $code,
            "msg" => $msg,
            "data" => $data,
        );

        return $pack;
    }

    public static function packEncode($data)
    {

        $guid = $data["guid"];
        $sendStr = serialize($data);

        //if compress the packet
        if (RpcConst::SW_DATACOMPRESS_FLAG == true) {
            $sendStr = gzencode($sendStr, 4);
        }

        if (RpcConst::SW_DATASIGEN_FLAG == true) {
            $signedcode = pack('N', crc32($sendStr . RpcConst::SW_DATASIGEN_SALT));
            $sendStr = pack('N', strlen($sendStr) + 4 + 32) . $signedcode . $guid . $sendStr;
        } else {
            $sendStr = pack('N', strlen($sendStr) + 32) . $guid . $sendStr;
        }

        return $sendStr;

    }

    public static function packDecode($str)
    {
        $header = substr($str, 0, 4);
        $len = unpack("Nlen", $header);
        $len = $len["len"];

        if (RpcConst::SW_DATASIGEN_FLAG == true) {

            $signedcode = substr($str, 4, 4);
            $guid = substr($str, 8, 32);
            $result = substr($str, 40);

            //check signed
            if (pack("N", crc32($result . RpcConst::SW_DATASIGEN_SALT)) != $signedcode) {
                return self::packFormat($guid, "Signed check error!", 100005);
            }

            $len = $len - 4 - 32;

        } else {
            $guid = substr($str, 4, 32);
            $result = substr($str, 36);
            $len = $len - 32;
        }
        if ($len != strlen($result)) {
            //结果长度不对
            return self::packFormat($guid, "packet length invalid 包长度非法", 100007);
        }
        //if compress the packet
        if (RpcConst::SW_DATACOMPRESS_FLAG == true) {
            $result = gzdecode($result);
        }
        $result = unserialize($result);

        return self::packFormat($guid, "OK", 0, $result);
    }
}
