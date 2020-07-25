<?php

namespace TableDog\Command;

/**
 * The base interface of all different commands.
 */
interface ICommand {
    public const TABLE_PREROUTING   = 0x00;
    public const TABLE_POSTROUTING  = 0x01;
}
