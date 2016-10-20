<?php

namespace Drupal\domain_path;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Path\AliasStorage;
use Drupal\domain\DomainNegotiatorInterface;

/**
 * Overrides AliasStorage.
 */
class DomainPathAliasStorage extends AliasStorage {

  /**
   * The table for the url_alias storage.
   */
  const TABLE = 'domain_path';

  protected $domain_id;

  protected $entity;

  /**
   * The domain negotiator.
   *
   * @var \Drupal\domain\DomainNegotiatorInterface
   */
  protected $domain_negotiator;

  public function __construct(Connection $connection, ModuleHandlerInterface $module_handler, DomainNegotiatorInterface $domain_negotiator) {
    $this->domain_negotiator = $domain_negotiator;
    parent::__construct($connection, $module_handler);
  }

  public function setDomainId($domain_id) {
    $this->domain_id = $domain_id;
    return $this;
  }

  public function getDomainId() {
    // If no domain id has been set, use the currently active one.
    if (empty($this->domain_id)) {
      $this->domain_id = $this->domain_negotiator->getActiveId();
    }
    return $this->domain_id;
  }

  public function setEntity(EntityInterface $entity) {
    $this->entity = $entity;
    return $this;
  }

  public function getEntity() {
    return $this->entity;
  }

  public function getEntityType() {
    $entity = $this->getEntity();
    if (!empty($entity)) {
      return $entity->getEntityType()->id();
    }
    return NULL;
  }

  public function getEntityId() {
    $entity = $this->getEntity();
    if (!empty($entity)) {
      return $entity->id();
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function save($source, $alias, $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, $pid = NULL) {

    if ($source[0] !== '/') {
      throw new \InvalidArgumentException(sprintf('Source path %s has to start with a slash.', $source));
    }

    if ($alias[0] !== '/') {
      throw new \InvalidArgumentException(sprintf('Alias path %s has to start with a slash.', $alias));
    }

    $fields = array(
      'source' => $source,
      'alias' => $alias,
      'langcode' => $langcode,
      'domain_id' => $this->getDomainId(),
      'entity_type' => $this->getEntityType(),
      'entity_id' => $this->getEntityId(),
    );

    // Insert or update the alias.
    if (empty($pid)) {
      $try_again = FALSE;
      try {
        $query = $this->connection->insert(static::TABLE)
          ->fields($fields);
        $pid = $query->execute();
      }
      catch (\Exception $e) {
        // If there was an exception, try to create the table.
        if (!$try_again = $this->ensureTableExists()) {
          // If the exception happened for other reason than the missing table,
          // propagate the exception.
          throw $e;
        }
      }
      // Now that the table has been created, try again if necessary.
      if ($try_again) {
        $query = $this->connection->insert(static::TABLE)
          ->fields($fields);
        $pid = $query->execute();
      }

      $fields['pid'] = $pid;
      $operation = 'insert';
    }
    else {
      // Fetch the current values so that an update hook can identify what
      // exactly changed.
      try {
        $original = $this->connection->query('SELECT source, alias, langcode FROM {' . static::TABLE . '} WHERE pid = :pid', array(':pid' => $pid))
          ->fetchAssoc();
      }
      catch (\Exception $e) {
        $this->catchException($e);
        $original = FALSE;
      }
      $fields['pid'] = $pid;
      $query = $this->connection->update(static::TABLE)
        ->fields($fields)
        ->condition('pid', $pid);
      $pid = $query->execute();
      $fields['original'] = $original;
      $operation = 'update';
    }
    if ($pid) {
      // @todo Switch to using an event for this instead of a hook.
      $this->moduleHandler->invokeAll('path_' . $operation, array($fields));
      Cache::invalidateTags(['route_match']);
      return $fields;
    }
    return FALSE;
  }


  /**
   * Defines the schema for the {domain_url_alias} table.
   */
  public static function schemaDefinition() {
    return [
      'description' => 'Stores per-domain path data.',
      'fields' => [
        'pid' => [
          'description' => 'Primary key.',
          'type' => 'serial',
          'not null' => TRUE,
        ],
        'domain_id' => [
          'description' => 'Domain id for this alias',
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'source' => [
          'description' => 'System path for the alias',
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
        ],
        'alias' => [
          'description' => 'Path alias for the domain',
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
        ],
        'langcode' => [
          'description' => 'Language for the alias.',
          'type' => 'varchar',
          'length' => 12,
          'not null' => TRUE,
          'default' => 'und',
        ],
        'entity_type' => [
          'description' => 'Entity type',
          'type' => 'varchar',
          'length' => 80,
          'not null' => FALSE,
        ],
        'entity_id' => [
          'description' => 'Entity id',
          'type' => 'int',
          'not null' => FALSE,
        ],
      ],
      'primary key' => ['pid'],
      'indexes' => [
        'alias_langcode_pid' => ['alias', 'langcode', 'pid'],
        'source_langcode_pid' => ['source', 'langcode', 'pid'],
      ],
    ];
  }

}