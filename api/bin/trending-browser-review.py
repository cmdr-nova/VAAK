#!/usr/bin/env python3
"""Compatibility entry point for the current lazy/current-policy sidebar review."""
import pathlib
import runpy

runpy.run_path(str(pathlib.Path(__file__).with_name('sidebar-lazy-browser-review.py')), run_name='__main__')
