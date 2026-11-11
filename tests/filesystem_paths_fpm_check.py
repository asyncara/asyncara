"""A generic HTTP 500 is insufficient evidence of the intended memory fatal."""
import re


def require_memory_fatal(text):
    entries = [line for line in text.splitlines() if '] PHP ' in line]
    if len(entries) != 1 or not re.search(
            r'\] PHP Fatal error:  Allowed memory size of \d+ bytes exhausted ', entries[0]):
        raise ValueError('Expected exactly one memory-exhaustion fatal and no PHP warnings/errors')


if __name__ == '__main__':
    import unittest

    class FatalEvidence(unittest.TestCase):
        expected = '[11-Nov-2026 20:00:00 UTC] PHP Fatal error:  Allowed memory size of 8388608 bytes exhausted (tried to allocate 12587008 bytes)\nStack trace:\n#0 example.php(1): asyncara_poll()\n'

        def test_memory_with_stack(self):
            require_memory_fatal(self.expected)

        def test_empty_or_generic_500(self):
            for value in ['', 'Status: 500 Internal Server Error']:
                with self.assertRaises(ValueError): require_memory_fatal(value)

        def test_wrong_fatal(self):
            with self.assertRaises(ValueError):
                require_memory_fatal('[11-Nov-2026 UTC] PHP Fatal error:  Uncaught RuntimeException: Fatal fixture has no captured staged owner\n')

        def test_warning_and_memory(self):
            with self.assertRaises(ValueError):
                require_memory_fatal('[11-Nov-2026 UTC] PHP Warning:  Directory not empty\n' + self.expected)

        def test_duplicate_memory_fatal(self):
            with self.assertRaises(ValueError): require_memory_fatal(self.expected * 2)

    unittest.main()
