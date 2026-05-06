"""Smoke test: verify that the agent_service package is importable."""


def test_import_agent_service() -> None:
    import agent_service

    assert agent_service.__version__ == "0.1.0"


def test_subpackages_importable() -> None:
    import agent_service.schemas
    import agent_service.workers
    import agent_service.rag
    import agent_service.eval

    assert True
