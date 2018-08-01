<?php
namespace DWDRPC;

class RpcConst
{
    //同步、异步不要结果、异步获取结果
    const SW_MODE_WAITRESULT = 0;
    const SW_MODE_NORESULT = 1;
    const SW_MODE_ASYNCRESULT = 2;

    //阻塞等待结果
    const SW_MODE_WAITRESULT_SINGLE = 'W_S';
    const SW_MODE_WAITRESULT_MULTI = 'W_M';

    //异步类型 不需要返回结果
    const SW_MODE_NORESULT_SINGLE = 'AN_S';
    const SW_MODE_NORESULT_MULTI = 'AN_M';

    //异步 需要返回结果
    const SW_MODE_ASYNCRESULT_SINGLE = 'AM_S';
    const SW_MODE_ASYNCRESULT_MULTI = 'AM_M';

    //命令行
    const SW_CONTROL_CMD = 'SC';

    //默认填充IP PORT模式
    const MODEL_DEFAULT = 1;
    //指定IP PORT填充
    const MODEL_APPOINT = 2;

    const SW_RECIVE_TIMEOUT = 3.0;

    //a flag to sure check the crc32
    //是否开启数据签名，服务端客户端都需要打开，打开后可以强化安全，但会降低一点性能
    const SW_DATASIGEN_FLAG = false;

    //a flag to decide if compress the packet
    //是否打开数据压缩，目前我们用的数据压缩是zlib的gzencode，压缩级别4
    const SW_DATACOMPRESS_FLAG = false;

    //salt to mixed the crc result
    //上面开关开启后，用于加密串混淆结果，请保持客户端和服务端一致
    const SW_DATASIGEN_SALT = "=&$*#@(*&%(@";
}
