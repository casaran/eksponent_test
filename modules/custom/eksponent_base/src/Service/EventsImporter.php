<?php

namespace Drupal\eksponent_base\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\node\Entity\Node;
use Drupal\file\FileRepository;
use Drupal\Component\Serialization\Json;

/**
 * EventsImporter service.
 *
 * Import external events into Drupal.
 */
class EventsImporter {

  /**
   * Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Http client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The file repository service under test.
   *
   * @var \Drupal\file\FileRepository
   */
  protected $fileRepository;


  /**
   * EventsImporter constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The http client.
   * @param \Drupal\file\FileRepository $fileRepository
   *   The file repository.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    Connection $connection,
    EntityFieldManagerInterface $entity_field_manager,
    ClientInterface $http_client,
    FileRepository $file_repository) {
    $this->entityTypeManager = $entityTypeManager;
    $this->connection = $connection;
    $this->entityFieldManager = $entity_field_manager;
    $this->httpClient = $http_client;
    $this->fileRepository = $file_repository;
  }

  /**
   * Sync Drupal events with external source.
   */
  public function sync() {
    $external_ids = $this->getEventsExternalIds();

    try {
      // @todo ideally implement some caching logic.
      $response = $this->httpClient->request('GET', 'https://eksponent.com/sites/default/files/sample-api/events.json');

      $external_events = Json::decode((string) $response->getBody());;
      // @todo ideally implement some batch processing.
      foreach ($external_events as $external_event) {
        $query = $this->entityTypeManager->getStorage('node')->getQuery();
        $nid = $query->accessCheck(FALSE)
          ->condition('type', 'event', '=')
          ->condition('field_external_id', $external_event['id'], '=')
          ->execute();
        // Only create if not exist already.
        // Or maybe updating existing content? Depends on requirements.
        if (!$nid) {
          $external_ids = $this->createEvent($external_event, $external_ids);
        }
      }
    }
    catch (RequestException $e) {
      if (!$e->hasResponse()) {
        throw $e;
      }
      $response = $e->getResponse();
    }

    // Delete events that have been deleted at the source.
    if (!empty($external_ids)) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $nids = $query->accessCheck(FALSE)
        ->condition('type', 'event', '=')
        ->condition('field_external_id', $external_ids, 'IN')
        ->execute();

      foreach($nids as $nid) {
        $node = Node::load($nid);
        $node->delete();
      }
    }
  }

  /**
   * Get existing events external IDs.
   */
  protected function getEventsExternalIds(): array {
    $date = new DrupalDateTime();
    $date->setTimezone(new \DateTimezone(DateTimeItemInterface::STORAGE_TIMEZONE));
    $formatted_date = $date->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);

    // Get all current events nids.
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $nids = $query->accessCheck(FALSE)
      ->condition('type', 'event', '=')
      ->condition('field_date.value', $formatted_date, '>=')
      ->execute();

    /** @var \Drupal\Core\Entity\Sql\TableMappingInterface $table_mapping*/
    $table_mapping = $this->entityTypeManager->getStorage('node')->getTableMapping();

    $field_table = $table_mapping->getFieldTableName('field_external_id');
    $field_storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions('node')['field_external_id'];
    $field_column = $table_mapping->getFieldColumnName($field_storage_definitions, 'value');

    // Get all current events external IDs.
    $external_ids = $this->connection->select($field_table, 'f')
      ->fields('f', array($field_column))
      ->distinct(TRUE)
      ->condition('bundle', 'event')
      ->condition('entity_id', $nids, 'IN')
      ->execute()->fetchCol();

    return $external_ids;
  }

  /**
   * Create a new event.
   */
  protected function createEvent(array $event, array $external_ids): array {
    // Image handling.
    $image_source_path  = file_get_contents($event['image']);
    $image_target_path = 'public://external_events/' . $event['id'];
    $image_data = file_get_contents($image_source_path);
    $image_object = $this->fileRepository->writeData($image_data, $image_target_path);

    // Date handling.
    $start_date = date('Y-m-d\TH:i:s', strtotime($event['start_date']));
    $end_date = date('Y-m-d\TH:i:s', strtotime($event['end_date']));

    $event = Node::create(['type' => 'event']);
    $event->set('title',$event['title']);
    $event->set('body', $event['description']);
    $event->set('field_date', [
      'value' => $start_date,
      'end_value' => $end_date,
    ]);
    $event->set('field_tickets', $event['available_ticlets']);
    $event->set('field_price', $event['price']['amount']);
    $event->set('field_organizer', ['target_id' => $event['organizer']['id']]);
    // @todo handle image alt.
    $event->set('field_primary_image', [
      'target_id' => $image_object->id(),
    ]);
    $event->enforceIsNew();
    $event->save();

    // Handling for deleted events on source.
    if (($key = array_search($event['id'], $external_ids)) !== false) {
      unset($external_ids[$key]);
    }

    return $external_ids;
  }

}
