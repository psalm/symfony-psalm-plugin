@symfony-5 @symfony-6
Feature: FormType templates

  Background:
    Given I have Symfony plugin enabled
  Scenario: FormExtension::getExtendedTypes must return iterables of FormTypeInterface
    Given I have the following code
          """
      <?php

      use Symfony\Component\Form\AbstractTypeExtension;
      use Symfony\Component\Form\Extension\Core\Type\FormType;

      class TestExtension extends AbstractTypeExtension
      {
          public static function getExtendedTypes(): iterable
          {
              yield FormType::class;
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: FormExtension::getExtendedTypes fails with incorrect types
    Given I have the following code
          """
      <?php

      use Symfony\Component\Form\AbstractTypeExtension;
      use Symfony\Component\Form\Extension\Core\Type\FormType;

      class TestExtension extends AbstractTypeExtension
      {
          public static function getExtendedTypes(): iterable
          {
              yield \stdClass::class;
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                       |
      | InvalidReturnType | The declared return type 'iterable<mixed, class-string<Symfony\Component\Form\FormTypeInterface>>' for TestExtension::getExtendedTypes is incorrect, got 'Generator<int, stdClass::class, mixed, void>' |

    And I see no other errors
