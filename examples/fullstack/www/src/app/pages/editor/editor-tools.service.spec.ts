import { TestBed } from '@angular/core/testing';

import { EditorToolsService } from './editor-tools.service';

describe('EditorToolsService', () => {
  let service: EditorToolsService;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    service = TestBed.inject(EditorToolsService);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });
});
