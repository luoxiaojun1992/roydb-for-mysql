<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Roydb;

/**
 */
class QueryClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts = []) {
        parent::__construct($hostname, $opts);
    }

    /**
     * @param \Roydb\SelectRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Roydb\SelectResponse[]|\Roydb\SelectResponse|\Grpc\StringifyAble[]
     */
    public function Select(\Roydb\SelectRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/roydb.Query/Select',
        $argument,
        ['\Roydb\SelectResponse', 'decode'],
        $metadata, $options);
    }

}
