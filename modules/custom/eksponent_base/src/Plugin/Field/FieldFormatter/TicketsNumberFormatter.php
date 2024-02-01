<?php

namespace Drupal\eksponent_base\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
* Plugin implementation of the 'TicketsNumberFormatter' formatter.
*
* @FieldFormatter(
*   id = "eksponent_base_tickets_number_formatter",
*   label = @Translation("Tickets Number Formatter"),
*   field_types = {
*     "integer"
*   }
* )
*/
class TicketsNumberFormatter extends FormatterBase  {

  public function viewElements(FieldItemListInterface $items,$langcode) {
    $elements = [];
    $markup = '';
    foreach ($items as $delta => $item) {
      if ($item->value == 0) {
        $markup = $this->t('SOLD OUT');
      }
      elseif ($item->value > 0 && $item->value <= 10 ) {
        $markup = $this->t('@tickets_left seats left', ['@tickets_left' => $item->value]);
      }

      $elements[$delta] = [
        '#markup' => $markup,
      ];
    }
    return $elements;
  }

}
