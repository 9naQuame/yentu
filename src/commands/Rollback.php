<?php

namespace yentu\commands;

use yentu\ChangeReverser;
use yentu\DatabaseManipulatorFactory;
use yentu\database\DatabaseItem;
use clearice\ConsoleIO;
use yentu\Yentu;

class Rollback
{

    private $schemaCondition;
    private $schemaConditionData = [];
    private $yentu;
    private $manipulatorFactory;
    private $io;

    public function __construct(Yentu $yentu, DatabaseManipulatorFactory $manipulatorFactory, ConsoleIO $io)
    {
        $this->yentu = $yentu;
        $this->manipulatorFactory = $manipulatorFactory;
        $this->io = $io;
    }

    public function run($options = array())
    {
        $this->yentu->greet();
        $db = $this->manipulatorFactory->createManipulator();
        DatabaseItem::setDriver($db);
        ChangeReverser::setDriver($db);
        $previousMigration = '';

        if (isset($options['default-schema'])) {
            $this->schemaCondition = "default_schema = ?";
            $this->schemaConditionData[] = $options['default-schema'];
        }

        if (empty($options)) {
            $session = $db->getLastSession();
            $operations = $db->query(
                "SELECT id, method, arguments, migration, default_schema FROM yentu_history WHERE $this->schemaCondition session = ? ORDER BY id DESC", $this->schemaConditionData + [$session]
            );
        } else {
            $operations = [];
            foreach ($options['stand_alones'] as $set) {
                $operations += $this->getOperations($db, $set);
            }
        }

        foreach ($operations as $operation) {
            if ($previousMigration !== $operation['migration']) {
                $this->io->output(
                    "Rolling back '{$operation['migration']}' migration" .
                    ($operation['default_schema'] != '' ? " on `{$operation['default_schema']}` schema." : ".") . "\n"
                );
                $previousMigration = $operation['migration'];
            }
            ChangeReverser::call($operation['method'], json_decode($operation['arguments'], true));
            $db->query('DELETE FROM yentu_history WHERE id = ?', array($operation['id']));
        }
    }

    private function getOperations($db, $set)
    {
        $operations = [];
        if (preg_match("/[0-9]{14}/", $set)) {
            $operations = $db->query(
                "SELECT id, method, arguments, migration, default_schema FROM yentu_history WHERE $this->schemaCondition version = ? ORDER by id DESC", $this->schemaConditionData + [$set]
            );
        } elseif (preg_match("/[a-zA-Z\_\-]+/", $set)) {
            $operations = $db->query(
                "SELECT id, method, arguments, migration, default_schema FROM yentu_history WHERE $this->schemaCondition migration = ? ORDER by id DESC", $this->schemaConditionData + [$set]
            );
        }
        return $operations;
    }

}
