<?php

class ShmopMQ
{
    const DATALENGTH_LENGTH = 10;
    
    private $_dataLengthShmKey;
    
    private $_dataShmKey;
    
    private $_dataLengthShm;
    
    private $_lock;
    
    public function __construct($file)
    {
        // set shmop keys
        $this->_dataLengthShmKey = ftok($file, 'a'); 
        $this->_dataShmKey = ftok($file, 'b');
        
        // set shmop for data length
        $this->_dataLengthShm = shmop_open($this->_dataLengthShmKey, 'c', 0644, self::DATALENGTH_LENGTH);
    }
    
    private function _getDataLength()
    {
        return (int) shmop_read($this->_dataLengthShm, 0, self::DATALENGTH_LENGTH);
    }
    
    private function _setDataLength($dataLength)
    {  
        // pad $dataLength string to length self::DATALENGTH_LENGTH and save
        shmop_write($this->_dataLengthShm, 
            str_pad((string) $dataLength, self::DATALENGTH_LENGTH, ' ', STR_PAD_RIGHT)
        , 0);
        
        return $this;
    }
    
    private function _getData()
    {        
        // get length of data in shared memory
        $dataLength = $this->_getDataLength();
        
        if(!$dataLength)
            return array();

        // read data from shared memory
        $dataShm = shmop_open($this->_dataShmKey, 'c', 0644, $dataLength);
        $serializedData = shmop_read($dataShm, 0, $dataLength);
        $data = @unserialize($serializedData);

        // destroy old data storage
        shmop_delete($dataShm);
        shmop_close($dataShm);
        
        // data broken
        if(!$data)
            return array();

        return $data;
    }
    
    private function _setData($data)
    {        
        // serialize data before storing to shared memory
        $data = serialize($data);
        
        // get new data length
        $dataLength = mb_strlen($data, 'UTF8');
        
        // storee new data length
        $this->_setDataLength($dataLength);
        
        // store new data
        $dataShm = shmop_open($this->_dataShmKey, 'c', 0644, $dataLength);
        shmop_write($dataShm, $data, 0);
        shmop_close($dataShm);
    }
    
    public function add($message)
    {
        // try to read lock file
        // if file locked - some process read or write to shared memory
        $lock = fopen(__FILE__, 'r');
        flock($lock, LOCK_EX);
        
        $data = $this->_getData();
        
        // add new message
        $data[] = $message;
        
        $this->_setData($data);
        
        // unlock
        flock($lock, LOCK_UN);
        fclose($lock);
        
    }
    
    public function get($messagesCount = 1)
    {
        // try to read lock file
        // if file locked - some process read or write to shared memory
        $lock = fopen(__FILE__, 'r');
        flock($lock, LOCK_EX);
        
        $data = $this->_getData();
        
        // add new message
        $message = array_slice($data, 0, $messagesCount);
        $data = array_slice($data, $messagesCount);
        
        $this->_setData($data);
        
        // unlock
        flock($lock, LOCK_UN);
        fclose($lock);
        
        //
        return $message;
    }
}
