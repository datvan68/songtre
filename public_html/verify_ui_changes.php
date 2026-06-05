<?php
// self-delete
unlink(__FILE__);
echo json_encode(["status" => "deleted"]);
