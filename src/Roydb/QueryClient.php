<?php

namespace SMProxy\Roydb;

/**
 *
 * @mixin \Roydb\QueryClient
 */
class QueryClient extends \Grpc\ClientStub
{

    protected $grpc_client = \Roydb\QueryClient::class;

}
